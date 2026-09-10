<?php

namespace Tests\Feature;

use App\Models\AcademicDegree;
use App\Models\Department;
use App\Models\ExternalCourse;
use App\Models\Hall;
use App\Models\Lap;
use App\Models\Lecturer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StoreScheduleExternalEntriesTest extends TestCase
{
    use DatabaseTransactions;

    private Lap $lap;

    private Hall $hall;

    private Lecturer $ta;

    private Lecturer $lecturer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lap = Lap::create([
            'name' => 'SchedStore Lab',
            'capacity' => 30,
            'labType' => 'general',
            'usedInNonSpecialistCourses' => true,
        ]);
        $this->hall = Hall::create(['name' => 'SchedStore Hall', 'capacity' => 100]);

        $department = Department::create(['name' => 'SchedStore Dept', 'name_ar' => 'قسم']);
        $taDegree = AcademicDegree::create(['name' => 'teaching assistant', 'prefix' => 'TA.']);
        $lecturerDegree = AcademicDegree::create(['name' => 'professor', 'prefix' => 'Prof.']);

        $this->ta = Lecturer::create([
            'name' => 'SchedStore TA',
            'name_ar' => 'معيد',
            'department_id' => $department->id,
            'academic_id' => $taDegree->id,
            'isPermanent' => true,
        ]);
        $this->lecturer = Lecturer::create([
            'name' => 'SchedStore Lecturer',
            'name_ar' => 'أستاذ',
            'department_id' => $department->id,
            'academic_id' => $lecturerDegree->id,
            'isPermanent' => true,
        ]);
    }

    private function createExternalCourse(array $overrides = []): ExternalCourse
    {
        return ExternalCourse::create(array_merge([
            'code' => 'EXT-S',
            'name_en' => 'Stored External',
            'name_ar' => 'خارجي',
            'requesting_entity_en' => 'Entity',
            'requesting_entity_ar' => 'جهة',
            'lab_groups' => 0,
            'lab_students_per_group' => null,
            'lecture_groups' => 1,
            'lecture_students_per_group' => 40,
            'lecture_venue' => 'ours',
        ], $overrides));
    }

    private function externalEntryPayload(ExternalCourse $course, array $overrides = []): array
    {
        return array_merge([
            'entry_kind' => 'external',
            'external_course_id' => $course->id,
            'course_ids' => [],
            'session_type' => 'lab',
            'group_info' => ['group_number' => 1, 'total_groups' => 2],
            'hall_id' => null,
            'lab_id' => $this->lap->id,
            'lecturer_id' => null,
            'time_slot' => [
                'day' => 'monday',
                'start_time' => '09:00',
                'end_time' => '11:00',
            ],
            'student_count' => 12,
            'academic_ids' => [],
            'academic_levels' => [],
            'department_ids' => [],
        ], $overrides);
    }

    private function storeSchedule(array $entries): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/schedules', [
            'nameEn' => 'External Test Schedule',
            'nameAr' => 'جدول تجريبي',
            'schedule' => $entries,
        ]);
    }

    public function test_v1_staffless_external_lab_stores_with_null_lecturer(): void
    {
        $course = $this->createExternalCourse([
            'lecture_groups' => 0,
            'lecture_students_per_group' => null,
            'lecture_venue' => null,
            'lab_groups' => 2,
            'lab_students_per_group' => 12,
        ]);

        $response = $this->storeSchedule([$this->externalEntryPayload($course)]);
        $response->assertStatus(201);

        $entry = $response->json('data.entries.0');
        $this->assertSame('external', $entry['entry_kind']);
        $this->assertNull($entry['staff']);
        $this->assertSame([], $entry['courses']);
        $this->assertSame([], $entry['academic_lists']);
    }

    public function test_external_lab_entry_persists_with_source_fields(): void
    {
        $course = $this->createExternalCourse([
            'lecture_groups' => 0,
            'lecture_students_per_group' => null,
            'lecture_venue' => null,
            'lab_groups' => 2,
            'lab_students_per_group' => 12,
        ]);

        $this->storeSchedule([$this->externalEntryPayload($course)])->assertStatus(201);

        $this->assertDatabaseHas('schedule_entries', [
            'entry_kind' => 'external',
            'external_course_id' => $course->id,
            'session_type' => 'lab',
            'lap_id' => $this->lap->id,
            'hall_id' => null,
            'lecturer_id' => null,
        ]);
    }

    public function test_v3_external_venue_lecture_stores_roomless_with_lecturer(): void
    {
        $course = $this->createExternalCourse(['lecture_venue' => 'external']);

        $response = $this->storeSchedule([
            $this->externalEntryPayload($course, [
                'session_type' => 'lecture',
                'lab_id' => null,
                'hall_id' => null,
                'lecturer_id' => $this->lecturer->id,
                'student_count' => 40,
            ]),
        ]);
        $response->assertStatus(201);

        $this->assertDatabaseHas('schedule_entries', [
            'entry_kind' => 'external',
            'session_type' => 'lecture',
            'hall_id' => null,
            'lap_id' => null,
            'lecturer_id' => $this->lecturer->id,
        ]);
    }

    public function test_external_venue_lecture_cannot_book_a_room(): void
    {
        $course = $this->createExternalCourse(['lecture_venue' => 'external']);

        $response = $this->storeSchedule([
            $this->externalEntryPayload($course, [
                'session_type' => 'lecture',
                'lab_id' => null,
                'hall_id' => $this->hall->id,
                'lecturer_id' => $this->lecturer->id,
            ]),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['schedule.0.hall_id']);
    }

    public function test_external_lab_requires_a_lab_and_rejects_halls(): void
    {
        $course = $this->createExternalCourse([
            'lecture_groups' => 0,
            'lecture_students_per_group' => null,
            'lecture_venue' => null,
            'lab_groups' => 2,
            'lab_students_per_group' => 12,
        ]);

        $missingLab = $this->storeSchedule([
            $this->externalEntryPayload($course, ['lab_id' => null]),
        ]);
        $missingLab->assertStatus(422);
        $missingLab->assertJsonValidationErrors(['schedule.0.lab_id']);

        $inHall = $this->storeSchedule([
            $this->externalEntryPayload($course, [
                'lab_id' => null,
                'hall_id' => $this->hall->id,
            ]),
        ]);
        $inHall->assertStatus(422);
        $inHall->assertJsonValidationErrors(['schedule.0.lab_id', 'schedule.0.hall_id']);
    }

    public function test_our_halls_external_lecture_requires_a_hall(): void
    {
        $course = $this->createExternalCourse();

        $response = $this->storeSchedule([
            $this->externalEntryPayload($course, [
                'session_type' => 'lecture',
                'lab_id' => null,
                'hall_id' => null,
                'lecturer_id' => $this->lecturer->id,
            ]),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['schedule.0.hall_id']);
    }

    public function test_exactly_one_source_is_enforced(): void
    {
        $course = $this->createExternalCourse([
            'lecture_groups' => 0,
            'lecture_students_per_group' => null,
            'lecture_venue' => null,
            'lab_groups' => 2,
            'lab_students_per_group' => 12,
        ]);

        // An external entry must not reference local courses.
        $withCourses = $this->storeSchedule([
            $this->externalEntryPayload($course, ['course_ids' => [1, 2]]),
        ]);
        $withCourses->assertStatus(422);
        $withCourses->assertJsonValidationErrors(['schedule.0.course_ids']);

        // A local course entry must not reference an external course.
        $withExternal = $this->storeSchedule([[
            'course_ids' => [1, 2],
            'session_type' => 'lab',
            'group_info' => ['group_number' => 1, 'total_groups' => 1],
            'hall_id' => null,
            'lab_id' => $this->lap->id,
            'lecturer_id' => $this->ta->id,
            'time_slot' => ['day' => 'monday', 'start_time' => '09:00', 'end_time' => '11:00'],
            'student_count' => 12,
            'academic_ids' => [],
            'academic_levels' => [3],
            'department_ids' => [],
            'external_course_id' => $course->id,
        ]]);
        $withExternal->assertStatus(422);
        $withExternal->assertJsonValidationErrors(['schedule.0.external_course_id']);
    }

    public function test_generation_context_emits_entry_kind_and_venue(): void
    {
        $course = $this->createExternalCourse(['lecture_venue' => 'external']);

        $stored = $this->storeSchedule([
            $this->externalEntryPayload($course, [
                'session_type' => 'lecture',
                'lab_id' => null,
                'hall_id' => null,
                'lecturer_id' => $this->lecturer->id,
            ]),
        ]);
        $scheduleId = $stored->json('data.id');

        $response = $this->getJson("/api/schedules/{$scheduleId}/generation-context");
        $response->assertStatus(200);

        $entry = $response->json('data.entries.0');
        $this->assertSame('external', $entry['entry_kind']);
        $this->assertSame($course->id, $entry['external_course_id']);
        $this->assertSame('external', $entry['lecture_venue']);
        $this->assertSame($this->lecturer->id, $entry['lecturer_id']);
    }

    public function test_course_entry_regression_stores_as_before(): void
    {
        $department = Department::where('name', 'SchedStore Dept')->first();
        $academic = \App\Models\Academic::create([
            'name' => 'SchedStore Academic',
            'name_ar' => 'قائمة',
            'department_id' => $department->id,
        ]);
        $localCourse = \App\Models\Course::create([
            'code' => 'LOC-1',
            'name_ar' => 'مقرر محلي',
            'name_en' => 'Local Course',
            'practical_components' => 'lab',
            'lecture_hours' => 2,
            'practical_hours' => 2,
            'credit_hours' => 3,
        ]);

        $response = $this->storeSchedule([[
            'course_ids' => [$localCourse->id],
            'session_type' => 'lecture',
            'group_info' => ['group_number' => 1, 'total_groups' => 1],
            'hall_id' => $this->hall->id,
            'lab_id' => null,
            'lecturer_id' => $this->lecturer->id,
            'time_slot' => ['day' => 'tuesday', 'start_time' => '13:00', 'end_time' => '15:00'],
            'student_count' => 50,
            'academic_ids' => [$academic->id],
            'academic_levels' => [3],
            'department_ids' => [$department->id],
        ]]);
        $response->assertStatus(201);

        $payload = $response->json('data.entries.0');
        $this->assertSame('course', $payload['entry_kind']);
        $this->assertNotNull($payload['staff']);
        // Course entries keep the historical shape: no external_course key.
        $this->assertArrayNotHasKey('external_course', $payload);

        // And the mandatory local fields are still enforced.
        $missingLecturer = $this->storeSchedule([[
            'course_ids' => [$localCourse->id],
            'session_type' => 'lecture',
            'group_info' => ['group_number' => 1, 'total_groups' => 1],
            'hall_id' => $this->hall->id,
            'lab_id' => null,
            'time_slot' => ['day' => 'tuesday', 'start_time' => '15:00', 'end_time' => '17:00'],
            'student_count' => 50,
            'academic_ids' => [$academic->id],
            'academic_levels' => [3],
            'department_ids' => [$department->id],
        ]]);
        $missingLecturer->assertStatus(422);
        $missingLecturer->assertJsonValidationErrors(['schedule.0.lecturer_id']);
    }

    // ------------------------------------------------------------------
    // Entry time rules: session hours + fixed time slots
    // ------------------------------------------------------------------

    public function test_one_hour_lab_entry_stores_when_the_course_declares_one_hour(): void
    {
        $course = $this->createExternalCourse([
            'lecture_groups' => 0,
            'lecture_students_per_group' => null,
            'lecture_venue' => null,
            'lab_groups' => 4,
            'lab_students_per_group' => 12,
            'lab_session_hours' => 1,
        ]);

        // A 1h session may start on any full hour (either half of a 2h slot).
        $secondHalf = $this->storeSchedule([
            $this->externalEntryPayload($course, [
                'group_info' => ['group_number' => 2, 'total_groups' => 4],
                'time_slot' => ['day' => 'monday', 'start_time' => '10:00', 'end_time' => '11:00'],
            ]),
        ]);
        $secondHalf->assertStatus(201);

        // Off-grid quarter-hour starts are still rejected.
        $response = $this->storeSchedule([
            $this->externalEntryPayload($course, [
                'group_info' => ['group_number' => 3, 'total_groups' => 4],
                'time_slot' => ['day' => 'monday', 'start_time' => '09:30', 'end_time' => '10:30'],
            ]),
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['schedule.0.time_slot.start_time']);

        $stored = $this->storeSchedule([
            $this->externalEntryPayload($course, [
                'time_slot' => ['day' => 'monday', 'start_time' => '09:00', 'end_time' => '10:00'],
            ]),
        ]);
        $stored->assertStatus(201);
        $this->assertDatabaseHas('schedule_entries', [
            'external_course_id' => $course->id,
            'startTime' => '09:00',
            'endTime' => '10:00',
        ]);
    }

    public function test_entry_span_must_match_the_configured_session_hours(): void
    {
        $oneHour = $this->createExternalCourse([
            'lecture_groups' => 0,
            'lecture_students_per_group' => null,
            'lecture_venue' => null,
            'lab_groups' => 2,
            'lab_students_per_group' => 12,
            'lab_session_hours' => 1,
        ]);

        $stretched = $this->storeSchedule([$this->externalEntryPayload($oneHour)]);
        $stretched->assertStatus(422);
        $stretched->assertJsonValidationErrors(['schedule.0.time_slot.end_time']);

        $twoHour = $this->createExternalCourse([
            'lecture_groups' => 0,
            'lecture_students_per_group' => null,
            'lecture_venue' => null,
            'lab_groups' => 2,
            'lab_students_per_group' => 12,
        ]);

        $halved = $this->storeSchedule([
            $this->externalEntryPayload($twoHour, [
                'time_slot' => ['day' => 'monday', 'start_time' => '09:00', 'end_time' => '10:00'],
            ]),
        ]);
        $halved->assertStatus(422);
        $halved->assertJsonValidationErrors(['schedule.0.time_slot.end_time']);
    }

    public function test_entry_must_sit_inside_the_declared_time_slots(): void
    {
        $slots = [['day' => 'monday', 'start_time' => '11:00', 'end_time' => '13:00']];
        $course = $this->createExternalCourse([
            'lecture_groups' => 0,
            'lecture_students_per_group' => null,
            'lecture_venue' => null,
            'lab_groups' => 4,
            'lab_students_per_group' => 12,
            'lab_session_hours' => 1,
            'lab_time_slots' => $slots,
        ]);

        // A valid 1h session but outside the declared set.
        $outside = $this->storeSchedule([
            $this->externalEntryPayload($course, [
                'time_slot' => ['day' => 'monday', 'start_time' => '09:00', 'end_time' => '10:00'],
            ]),
        ]);
        $outside->assertStatus(422);
        $outside->assertJsonValidationErrors(['schedule.0.time_slot']);

        // First half of the declared slot.
        $this->storeSchedule([
            $this->externalEntryPayload($course, [
                'group_info' => ['group_number' => 1, 'total_groups' => 4],
                'time_slot' => ['day' => 'monday', 'start_time' => '11:00', 'end_time' => '12:00'],
            ]),
        ])->assertStatus(201);

        // Second half of the declared slot.
        $this->storeSchedule([
            $this->externalEntryPayload($course, [
                'group_info' => ['group_number' => 2, 'total_groups' => 4],
                'time_slot' => ['day' => 'monday', 'start_time' => '12:00', 'end_time' => '13:00'],
            ]),
        ])->assertStatus(201);
    }

    public function test_lecture_session_hours_gate_lecture_entries(): void
    {
        $slots = [['day' => 'sunday', 'start_time' => '09:00', 'end_time' => '11:00']];
        $course = $this->createExternalCourse([
            'lecture_session_hours' => 1,
            'lecture_time_slots' => $slots,
        ]);

        $ok = $this->storeSchedule([
            $this->externalEntryPayload($course, [
                'session_type' => 'lecture',
                'lab_id' => null,
                'hall_id' => $this->hall->id,
                'lecturer_id' => $this->lecturer->id,
                'student_count' => 40,
                'time_slot' => ['day' => 'sunday', 'start_time' => '09:00', 'end_time' => '10:00'],
            ]),
        ]);
        $ok->assertStatus(201);

        $stretched = $this->storeSchedule([
            $this->externalEntryPayload($course, [
                'session_type' => 'lecture',
                'lab_id' => null,
                'hall_id' => $this->hall->id,
                'lecturer_id' => $this->lecturer->id,
                'student_count' => 40,
                'time_slot' => ['day' => 'sunday', 'start_time' => '09:00', 'end_time' => '11:00'],
            ]),
        ]);
        $stretched->assertStatus(422);
        $stretched->assertJsonValidationErrors(['schedule.0.time_slot.end_time']);
    }

    public function test_schedule_read_path_renders_external_entries(): void
    {
        $course = $this->createExternalCourse([
            'lecture_groups' => 0,
            'lecture_students_per_group' => null,
            'lecture_venue' => null,
            'lab_groups' => 2,
            'lab_students_per_group' => 12,
        ]);

        $stored = $this->storeSchedule([$this->externalEntryPayload($course)]);
        $scheduleId = $stored->json('data.id');

        $response = $this->getJson("/api/schedules/{$scheduleId}");
        $response->assertStatus(200);

        $entry = $response->json('data.entries.0');
        $this->assertSame('external', $entry['entry_kind']);
        $this->assertSame($course->id, $entry['external_course']['id']);
        $this->assertSame('Stored External', $entry['external_course']['name']);
        $this->assertSame([], $entry['academic_lists']);
        $this->assertNull($entry['staff']);

        // Arabic locale resolves the Arabic labels.
        $arabic = $this->getJson("/api/schedules/{$scheduleId}", ['Accept-Language' => 'ar']);
        $arabicEntry = $arabic->json('data.entries.0');
        $this->assertSame('خارجي', $arabicEntry['external_course']['name']);
        $this->assertSame('جهة', $arabicEntry['external_course']['requesting_entity']);

        $metadata = $response->json('data.metadata');
        $this->assertSame(1, $metadata['total_courses']);
        $this->assertSame(0, $metadata['total_staff']);
        $this->assertSame(1, $metadata['total_rooms']);
    }
}
