<?php

namespace Tests\Feature;

use App\Models\AcademicDegree;
use App\Models\Department;
use App\Models\ExternalCourse;
use App\Models\Lap;
use App\Models\Lecturer;
use App\Models\Schedule;
use App\Models\ScheduleEntry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExternalCourseApiTest extends TestCase
{
    use DatabaseTransactions;

    private Lap $lap;

    private Lecturer $ta;

    private Lecturer $lecturer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lap = Lap::create([
            'name' => 'ExtTest Lab',
            'capacity' => 30,
            'labType' => 'general',
            'usedInNonSpecialistCourses' => true,
        ]);

        $department = Department::create(['name' => 'ExtTest Dept', 'name_ar' => 'قسم']);
        $degree = AcademicDegree::create(['name' => 'teaching assistant', 'prefix' => 'TA.']);
        $lecturerDegree = AcademicDegree::create(['name' => 'professor', 'prefix' => 'Prof.']);

        $this->ta = Lecturer::create([
            'name' => 'Test TA',
            'name_ar' => 'معيد',
            'department_id' => $department->id,
            'academic_id' => $degree->id,
            'isPermanent' => true,
        ]);
        $this->lecturer = Lecturer::create([
            'name' => 'Test Lecturer',
            'name_ar' => 'أستاذ',
            'department_id' => $department->id,
            'academic_id' => $lecturerDegree->id,
            'isPermanent' => true,
        ]);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'EXT-101',
            'name_en' => 'External Physics',
            'name_ar' => 'فيزياء خارجية',
            'requesting_entity_en' => 'Faculty of Science',
            'requesting_entity_ar' => 'كلية العلوم',
            'lab_groups' => 0,
            'lab_students_per_group' => null,
            'lecture_groups' => 2,
            'lecture_students_per_group' => 40,
            'lecture_venue' => 'ours',
            'eligible_lab_ids' => [],
            'staff' => [],
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // Variant validation matrix
    // ------------------------------------------------------------------

    public function test_v1_staffless_labs_only_is_accepted(): void
    {
        $response = $this->postJson('/api/external-courses', $this->basePayload([
            'lecture_groups' => 0,
            'lecture_students_per_group' => null,
            'lecture_venue' => null,
            'lab_groups' => 2,
            'lab_students_per_group' => 12,
            'eligible_lab_ids' => [$this->lap->id],
        ]));

        $response->assertStatus(201);
        $course = ExternalCourse::findOrFail($response->json('data.id'));
        $this->assertSame(0, $course->staffAssignments()->count());
        $this->assertSame([$this->lap->id], $course->eligibleLabs()->pluck('laps.id')->all());
    }

    public function test_v2_ta_distribution_is_accepted(): void
    {
        $response = $this->postJson('/api/external-courses', $this->basePayload([
            'lecture_groups' => 0,
            'lecture_students_per_group' => null,
            'lecture_venue' => null,
            'lab_groups' => 5,
            'lab_students_per_group' => 12,
            'staff' => [
                ['staff_id' => $this->ta->id, 'role' => 'ta', 'num_of_groups' => 3],
                ['staff_id' => $this->lecturer->id, 'role' => 'ta', 'num_of_groups' => 2],
            ],
        ]));

        $response->assertStatus(201);
        $course = ExternalCourse::with('staffAssignments')->find($response->json('data.id'));
        $this->assertCount(2, $course->staffAssignments);
    }

    public function test_v4_fully_regular_shape_is_accepted(): void
    {
        $response = $this->postJson('/api/external-courses', $this->basePayload([
            'lab_groups' => 2,
            'lab_students_per_group' => 12,
            'staff' => [
                ['staff_id' => $this->ta->id, 'role' => 'ta', 'num_of_groups' => 2],
                ['staff_id' => $this->lecturer->id, 'role' => 'lecturer', 'num_of_groups' => 2],
            ],
        ]));

        $response->assertStatus(201);
    }

    public function test_v5_our_halls_lecture_without_lecturer_is_accepted(): void
    {
        $response = $this->postJson('/api/external-courses', $this->basePayload());

        $response->assertStatus(201);
        $course = ExternalCourse::findOrFail($response->json('data.id'));
        $this->assertTrue($course->hasExternalLecturer());
    }

    public function test_v3_external_venue_without_lecturer_is_rejected(): void
    {
        // A roomless staffless lecture would reserve nothing at all.
        $response = $this->postJson('/api/external-courses', $this->basePayload([
            'lecture_venue' => 'external',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['staff']);
    }

    public function test_partial_lab_coverage_is_rejected(): void
    {
        $response = $this->postJson('/api/external-courses', $this->basePayload([
            'lecture_groups' => 0,
            'lecture_students_per_group' => null,
            'lecture_venue' => null,
            'lab_groups' => 5,
            'lab_students_per_group' => 12,
            'staff' => [
                ['staff_id' => $this->ta->id, 'role' => 'ta', 'num_of_groups' => 3],
            ],
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['staff']);
    }

    public function test_course_without_any_component_is_rejected(): void
    {
        $response = $this->postJson('/api/external-courses', $this->basePayload([
            'lecture_groups' => 0,
            'lecture_students_per_group' => null,
            'lecture_venue' => null,
            'lab_groups' => 0,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['lab_groups']);
    }

    public function test_students_per_group_is_required_with_its_component(): void
    {
        $response = $this->postJson('/api/external-courses', $this->basePayload([
            'lab_groups' => 2,
            'lab_students_per_group' => null,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['lab_students_per_group']);
    }

    public function test_venue_is_required_with_lecture_component_and_forbidden_without(): void
    {
        $missing = $this->postJson('/api/external-courses', $this->basePayload([
            'lecture_venue' => null,
        ]));
        $missing->assertStatus(422);
        $missing->assertJsonValidationErrors(['lecture_venue']);

        $orphan = $this->postJson('/api/external-courses', $this->basePayload([
            'lecture_groups' => 0,
            'lecture_students_per_group' => null,
            'lab_groups' => 1,
            'lab_students_per_group' => 10,
            'staff' => [
                ['staff_id' => $this->ta->id, 'role' => 'ta', 'num_of_groups' => 1],
            ],
            'lecture_venue' => 'ours',
        ]));
        $orphan->assertStatus(422);
        $orphan->assertJsonValidationErrors(['lecture_venue']);
    }

    public function test_role_rows_must_match_their_component(): void
    {
        $taWithoutLabs = $this->postJson('/api/external-courses', $this->basePayload([
            'staff' => [
                ['staff_id' => $this->ta->id, 'role' => 'ta', 'num_of_groups' => 1],
            ],
        ]));
        $taWithoutLabs->assertStatus(422);
        $taWithoutLabs->assertJsonValidationErrors(['staff.0.role']);

        $lecturerWithoutLectures = $this->postJson('/api/external-courses', $this->basePayload([
            'lecture_groups' => 0,
            'lecture_students_per_group' => null,
            'lecture_venue' => null,
            'lab_groups' => 2,
            'lab_students_per_group' => 12,
            'staff' => [
                ['staff_id' => $this->lecturer->id, 'role' => 'lecturer', 'num_of_groups' => 2],
            ],
        ]));
        $lecturerWithoutLectures->assertStatus(422);
        $lecturerWithoutLectures->assertJsonValidationErrors(['staff.0.role']);
    }

    public function test_lecture_distribution_must_cover_every_group(): void
    {
        $response = $this->postJson('/api/external-courses', $this->basePayload([
            'staff' => [
                ['staff_id' => $this->lecturer->id, 'role' => 'lecturer', 'num_of_groups' => 1],
            ],
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['staff']);
    }

    // ------------------------------------------------------------------
    // Scheduling configuration: session hours + fixed time slots
    // ------------------------------------------------------------------

    public function test_session_hours_and_time_slots_persist_and_roundtrip(): void
    {
        $slots = [
            ['day' => 'monday', 'start_time' => '09:00', 'end_time' => '11:00'],
            ['day' => 'wednesday', 'start_time' => '11:00', 'end_time' => '13:00'],
        ];

        $created = $this->postJson('/api/external-courses', $this->basePayload([
            'lecture_groups' => 0,
            'lecture_students_per_group' => null,
            'lecture_venue' => null,
            'lab_groups' => 4,
            'lab_students_per_group' => 12,
            'lab_session_hours' => 1,
            'lab_time_slots' => $slots,
        ]));
        $created->assertStatus(201);
        $data = $created->json('data');
        $this->assertSame(1, $data['lab_session_hours']);
        $this->assertSame(2, $data['lecture_session_hours']);
        // MySQL's JSON column normalizes object key order — compare loosely.
        $this->assertEquals($slots, $data['lab_time_slots']);

        // The generation fetch (?ids[]) carries the configuration too.
        $fetched = $this->getJson("/api/external-courses?ids[]={$data['id']}");
        $fetched->assertStatus(200);
        $this->assertSame(1, $fetched->json('data.0.lab_session_hours'));
        $this->assertEquals($slots, $fetched->json('data.0.lab_time_slots'));

        // Editing clears the set and changes the span.
        $updated = $this->patchJson("/api/external-courses/{$data['id']}", $this->basePayload([
            'lecture_groups' => 0,
            'lecture_students_per_group' => null,
            'lecture_venue' => null,
            'lab_groups' => 4,
            'lab_students_per_group' => 12,
            'lab_session_hours' => 2,
            'lab_time_slots' => null,
        ]));
        $updated->assertStatus(200);
        $course = ExternalCourse::findOrFail($data['id']);
        $this->assertSame(2, $course->lab_session_hours);
        $this->assertNull($course->lab_time_slots);
    }

    public function test_scheduling_config_is_rejected_without_its_component(): void
    {
        // Lab config on a lecture-only course.
        $labConfig = $this->postJson('/api/external-courses', $this->basePayload([
            'lab_session_hours' => 1,
            'lab_time_slots' => [['day' => 'monday', 'start_time' => '09:00', 'end_time' => '11:00']],
        ]));
        $labConfig->assertStatus(422);
        $labConfig->assertJsonValidationErrors(['lab_time_slots', 'lab_session_hours']);

        // Lecture config on a lab-only course.
        $lectureConfig = $this->postJson('/api/external-courses', $this->basePayload([
            'lecture_groups' => 0,
            'lecture_students_per_group' => null,
            'lecture_venue' => null,
            'lab_groups' => 2,
            'lab_students_per_group' => 12,
            'lecture_session_hours' => 1,
            'lecture_time_slots' => [['day' => 'monday', 'start_time' => '09:00', 'end_time' => '11:00']],
        ]));
        $lectureConfig->assertStatus(422);
        $lectureConfig->assertJsonValidationErrors(['lecture_time_slots', 'lecture_session_hours']);
    }

    public function test_time_slots_must_be_whole_on_grid_periods_without_duplicates(): void
    {
        $base = [
            'lecture_groups' => 0,
            'lecture_students_per_group' => null,
            'lecture_venue' => null,
            'lab_groups' => 2,
            'lab_students_per_group' => 12,
        ];

        // Off-grid start (10:00 is not a 2h grid start).
        $offGrid = $this->postJson('/api/external-courses', $this->basePayload($base + [
            'lab_time_slots' => [['day' => 'monday', 'start_time' => '10:00', 'end_time' => '12:00']],
        ]));
        $offGrid->assertStatus(422);
        $offGrid->assertJsonValidationErrors(['lab_time_slots.0.start_time']);

        // Span must be exactly 2 hours.
        $shortSpan = $this->postJson('/api/external-courses', $this->basePayload($base + [
            'lab_time_slots' => [['day' => 'monday', 'start_time' => '09:00', 'end_time' => '10:00']],
        ]));
        $shortSpan->assertStatus(422);
        $shortSpan->assertJsonValidationErrors(['lab_time_slots.0.end_time']);

        // Friday is not a scheduling day.
        $friday = $this->postJson('/api/external-courses', $this->basePayload($base + [
            'lab_time_slots' => [['day' => 'friday', 'start_time' => '09:00', 'end_time' => '11:00']],
        ]));
        $friday->assertStatus(422);
        $friday->assertJsonValidationErrors(['lab_time_slots.0.day']);

        // The same slot declared twice.
        $duplicate = $this->postJson('/api/external-courses', $this->basePayload($base + [
            'lab_time_slots' => [
                ['day' => 'monday', 'start_time' => '09:00', 'end_time' => '11:00'],
                ['day' => 'monday', 'start_time' => '09:00', 'end_time' => '11:00'],
            ],
        ]));
        $duplicate->assertStatus(422);
        $duplicate->assertJsonValidationErrors(['lab_time_slots.1.day']);

        // Session hours outside {1, 2}.
        $hours = $this->postJson('/api/external-courses', $this->basePayload($base + [
            'lab_session_hours' => 3,
        ]));
        $hours->assertStatus(422);
        $hours->assertJsonValidationErrors(['lab_session_hours']);
    }

    // ------------------------------------------------------------------
    // CRUD behaviour
    // ------------------------------------------------------------------

    public function test_index_supports_ids_filter_and_returns_generation_payload(): void
    {
        $course = ExternalCourse::create($this->courseRow());
        $course->eligibleLabs()->sync([$this->lap->id]);
        $course->staffAssignments()->create([
            'staff_id' => $this->ta->id, 'role' => 'ta', 'num_of_groups' => 2,
        ]);

        $response = $this->getJson("/api/external-courses?ids[]={$course->id}");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($course->id, $data[0]['id']);
        $this->assertSame($this->lap->id, $data[0]['eligible_labs'][0]['id']);
        $this->assertSame('ta', $data[0]['staff'][0]['role']);
        $this->assertSame('Test TA', $data[0]['staff'][0]['name']);
    }

    public function test_update_replaces_distributions_and_pivot(): void
    {
        $course = ExternalCourse::create($this->courseRow());
        $course->eligibleLabs()->sync([$this->lap->id]);
        $course->staffAssignments()->create([
            'staff_id' => $this->ta->id, 'role' => 'ta', 'num_of_groups' => 2,
        ]);

        $response = $this->patchJson("/api/external-courses/{$course->id}", $this->basePayload([
            'name_en' => 'Renamed External Course',
            'lab_groups' => 3,
            'lab_students_per_group' => 15,
            'lecture_groups' => 0,
            'lecture_students_per_group' => null,
            'lecture_venue' => null,
            'staff' => [
                ['staff_id' => $this->ta->id, 'role' => 'ta', 'num_of_groups' => 3],
            ],
        ]));

        $response->assertStatus(200);
        $course->refresh();
        $this->assertSame('Renamed External Course', $course->name_en);
        $this->assertSame(3, $course->lab_groups);
        $this->assertSame(1, $course->staffAssignments()->count());
        $this->assertSame(3, $course->staffAssignments()->first()->num_of_groups);
        $this->assertSame([], $course->eligibleLabs()->pluck('laps.id')->all());
    }

    public function test_delete_is_blocked_while_schedule_history_references_the_course(): void
    {
        $course = ExternalCourse::create($this->courseRow());
        $schedule = Schedule::create(['nameEn' => 'S', 'nameAr' => 'S']);
        ScheduleEntry::create([
            'schedule_id' => $schedule->id,
            'entry_kind' => 'external',
            'external_course_id' => $course->id,
            'course_ids' => [],
            'session_type' => 'lab',
            'group_number' => 1,
            'total_groups' => 1,
            'lap_id' => $this->lap->id,
            'Day' => 'monday',
            'startTime' => '09:00',
            'endTime' => '11:00',
            'student_count' => 10,
            'academic_ids' => [],
            'academic_levels' => [],
            'department_ids' => [],
        ]);

        $this->deleteJson("/api/external-courses/{$course->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('external_courses', ['id' => $course->id]);

        // An unreferenced course deletes fine.
        $other = ExternalCourse::create($this->courseRow());
        $this->deleteJson("/api/external-courses/{$other->id}")->assertStatus(200);
        $this->assertDatabaseMissing('external_courses', ['id' => $other->id]);
    }

    private function courseRow(): array
    {
        return [
            'code' => 'EXT-ROW',
            'name_en' => 'Row Course',
            'name_ar' => 'مقرر',
            'requesting_entity_en' => 'Entity',
            'requesting_entity_ar' => 'جهة',
            'lab_groups' => 0,
            'lab_students_per_group' => null,
            'lecture_groups' => 1,
            'lecture_students_per_group' => 30,
            'lecture_venue' => 'ours',
        ];
    }
}
