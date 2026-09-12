<?php

namespace Tests\Feature;

use App\Models\Academic;
use App\Models\AcademicDegree;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\Department;
use App\Models\ExternalCourse;
use App\Models\Hall;
use App\Models\Lap;
use App\Models\Lecturer;
use App\Models\Schedule;
use App\Models\StudyPlane;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Manual add/delete of schedule entries (the web editor's block operations).
 * The engine is faked at the HTTP boundary: happy-path fakes return ok, and
 * dedicated tests cover the fail-closed paths (engine reject, engine down).
 */
class ManualEntryOperationsTest extends TestCase
{
    use DatabaseTransactions;

    private Schedule $schedule;

    private Lap $lap;

    private Hall $hall;

    private Lecturer $lecturer;

    private Department $department;

    private Academic $academic;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lap = Lap::create([
            'name' => 'Manual Ops Lab',
            'capacity' => 30,
            'labType' => 'general',
            'usedInNonSpecialistCourses' => true,
        ]);
        $this->hall = Hall::create(['name' => 'Manual Ops Hall', 'capacity' => 100]);

        $this->department = Department::create(['name' => 'Manual Ops Dept', 'name_ar' => 'قسم']);
        $lecturerDegree = AcademicDegree::create(['name' => 'professor', 'prefix' => 'Prof.']);

        $this->lecturer = Lecturer::create([
            'name' => 'Manual Ops Lecturer',
            'name_ar' => 'أستاذ',
            'department_id' => $this->department->id,
            'academic_id' => $lecturerDegree->id,
            'isPermanent' => true,
        ]);

        $this->academic = Academic::create([
            'name' => 'Manual Ops Academic',
            'name_ar' => 'قائمة',
            'department_id' => $this->department->id,
        ]);

        $this->course = Course::create([
            'code' => 'MO-101',
            'name_ar' => 'مقرر',
            'name_en' => 'Manual Ops Course',
            'practical_components' => 'lab',
            'lecture_hours' => 2,
            'practical_hours' => 2,
            'credit_hours' => 3,
        ]);

        $this->schedule = Schedule::create([
            'nameEn' => 'Manual Ops Schedule',
            'nameAr' => 'جدول',
        ]);
        $this->seedEntry(['session_type' => 'lab', 'lab_id' => $this->lap->id]);
        $this->seedEntry(['session_type' => 'lecture', 'hall_id' => $this->hall->id]);

        // No engine fake here on purpose: repeated Http::fake() calls stack
        // (the first stub wins), so each test registers exactly what it needs.
    }

    private function seedEntry(array $overrides = []): void
    {
        $this->schedule->entries()->create(array_merge([
            'entry_kind' => 'course',
            'course_ids' => [$this->course->id],
            'session_type' => 'lab',
            'group_number' => 1,
            'total_groups' => 2,
            'hall_id' => null,
            'lap_id' => $this->lap->id,
            'lecturer_id' => $this->lecturer->id,
            'Day' => 'monday',
            'startTime' => '09:00',
            'endTime' => '11:00',
            'student_count' => 25,
            'academic_ids' => [$this->academic->id],
            'academic_levels' => [2],
            'department_ids' => [$this->department->id],
        ], $overrides));
    }

    /**
     * Validate answers ok; apply (apply=true) confirms the commit.
     */
    private function fakeEngineOk(): void
    {
        Http::fake([
            '*/validate-change' => function (Request $request) {
                if (($request['apply'] ?? null) === true) {
                    return Http::response(['ok' => true, 'applied' => true]);
                }

                return Http::response(['ok' => true, 'hard_conflicts' => [], 'soft_deltas' => []]);
            },
        ]);
    }

    private function addPayload(array $overrides = []): array
    {
        return array_merge([
            'entry_kind' => 'course',
            'external_course_id' => null,
            'course_ids' => [$this->course->id],
            'session_type' => 'lab',
            'group_number' => 2,
            'total_groups' => 2,
            'day' => 'tuesday',
            'start_time' => '13:00',
            'end_time' => '15:00',
            'lab_id' => $this->lap->id,
            'hall_id' => null,
            'lecturer_id' => $this->lecturer->id,
            'student_count' => 25,
            'academic_ids' => [$this->academic->id],
            'academic_levels' => [2],
            'department_ids' => [$this->department->id],
            'block_key' => '["1920","lab",[["5",2]],2,2,7]',
        ], $overrides);
    }

    public function test_add_creates_entry_with_snapshots_and_applies_to_engine(): void
    {
        $this->fakeEngineOk();

        $response = $this->postJson(
            "/api/schedules/{$this->schedule->id}/entries",
            $this->addPayload()
        );

        $response->assertStatus(201);
        $entryId = $response->json('data.entry.id');
        $this->assertNotNull($entryId);
        $this->assertNotNull($response->json('data.updated_at'));

        $this->assertDatabaseHas('schedule_entries', [
            'id' => $entryId,
            'session_type' => 'lab',
            'lap_id' => $this->lap->id,
            'lap_name' => 'Manual Ops Lab',
            'lecturer_name' => 'Manual Ops Lecturer',
            'group_number' => 2,
        ]);

        // Validate ran before the write; the apply carried the new row id.
        Http::assertSent(function (Request $request) use ($entryId) {
            if (($request['apply'] ?? null) === true) {
                return $request['change']['new_entry_id'] === $entryId
                    && $request['change']['block_key'] === $this->addPayload()['block_key'];
            }

            return $request['change']['type'] === 'add'
                && $request['change']['block_key'] === $this->addPayload()['block_key']
                && $request['change']['room'] === ['type' => 'lab', 'id' => $this->lap->id];
        });
    }

    public function test_add_external_roomless_lecture_stores_without_room(): void
    {
        $this->fakeEngineOk();

        $external = ExternalCourse::create([
            'code' => 'MO-EXT',
            'name_en' => 'Manual Ops External',
            'name_ar' => 'خارجي',
            'requesting_entity_en' => 'Entity',
            'requesting_entity_ar' => 'جهة',
            'lab_groups' => 0,
            'lecture_groups' => 1,
            'lecture_students_per_group' => 40,
            'lecture_venue' => 'external',
        ]);

        $response = $this->postJson(
            "/api/schedules/{$this->schedule->id}/entries",
            $this->addPayload([
                'entry_kind' => 'external',
                'external_course_id' => $external->id,
                'course_ids' => [],
                'session_type' => 'lecture',
                'hall_id' => null,
                'lab_id' => null,
                'student_count' => 40,
                'requires_room' => false,
            ])
        );

        $response->assertStatus(201);
        $this->assertDatabaseHas('schedule_entries', [
            'entry_kind' => 'external',
            'external_course_id' => $external->id,
            'hall_id' => null,
            'lap_id' => null,
        ]);
    }

    public function test_add_is_rejected_when_the_engine_rejects(): void
    {
        Http::fake([
            '*/validate-change' => Http::response([
                'ok' => false,
                'hard_conflicts' => [
                    ['code' => 'ROOM_CONFLICT', 'message' => 'No double room booking'],
                ],
            ]),
        ]);

        $before = $this->schedule->entries()->count();
        $response = $this->postJson(
            "/api/schedules/{$this->schedule->id}/entries",
            $this->addPayload()
        );

        $response->assertStatus(422);
        // reject() merges the engine payload at the top level.
        $this->assertSame('ROOM_CONFLICT', $response->json('hard_conflicts.0.code'));
        $this->assertSame($before, $this->schedule->entries()->count());
    }

    public function test_add_fails_closed_when_the_engine_is_down(): void
    {
        Http::fake(['*/validate-change' => Http::response(['detail' => 'down'], 503)]);

        $before = $this->schedule->entries()->count();
        $response = $this->postJson(
            "/api/schedules/{$this->schedule->id}/entries",
            $this->addPayload()
        );

        $response->assertStatus(503);
        $this->assertFalse($response->json('engine_available'));
        $this->assertSame($before, $this->schedule->entries()->count());
    }

    public function test_add_is_refused_on_version_mismatch(): void
    {
        $before = $this->schedule->entries()->count();
        $response = $this->postJson(
            "/api/schedules/{$this->schedule->id}/entries",
            $this->addPayload(),
            ['X-Schedule-Version' => '2020-01-01T00:00:00.000000Z']
        );

        $response->assertStatus(409);
        $this->assertSame($before, $this->schedule->entries()->count());
    }

    public function test_add_lets_the_engine_decide_the_room_type(): void
    {
        // Generation itself places requirement-free lab sessions in halls, so
        // a lab session + hall must NOT be refused here — the engine's verdict
        // (faked ok in this test) is the single authority on room legality.
        $this->fakeEngineOk();

        $response = $this->postJson(
            "/api/schedules/{$this->schedule->id}/entries",
            $this->addPayload(['lab_id' => null, 'hall_id' => $this->hall->id])
        );

        $response->assertStatus(201);
        $this->assertDatabaseHas('schedule_entries', [
            'session_type' => 'lab',
            'hall_id' => $this->hall->id,
            'hall_name' => 'Manual Ops Hall',
        ]);
    }

    public function test_add_requires_a_room_for_roomed_blocks(): void
    {
        $response = $this->postJson(
            "/api/schedules/{$this->schedule->id}/entries",
            $this->addPayload(['lab_id' => null, 'hall_id' => null])
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['hall_id']);
    }

    public function test_delete_removes_the_entry_and_applies_to_engine(): void
    {
        $this->fakeEngineOk();

        $entry = $this->schedule->entries()->first();
        $response = $this->deleteJson(
            "/api/schedules/{$this->schedule->id}/entries/{$entry->id}"
        );

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.deleted'));
        $this->assertNotNull($response->json('data.updated_at'));
        $this->assertDatabaseMissing('schedule_entries', ['id' => $entry->id]);

        Http::assertSent(function (Request $request) use ($entry) {
            return $request['change']['type'] === 'remove'
                && $request['change']['entry_id'] === $entry->id;
        });
    }

    public function test_delete_refuses_the_last_remaining_entry(): void
    {
        $this->schedule->entries()->where('id', '!=', $this->schedule->entries()->first()->id)->delete();
        $entry = $this->schedule->entries()->first();

        $response = $this->deleteJson(
            "/api/schedules/{$this->schedule->id}/entries/{$entry->id}"
        );

        $response->assertStatus(422);
        $this->assertDatabaseHas('schedule_entries', ['id' => $entry->id]);
        Http::assertNothingSent();
    }

    public function test_delete_is_refused_on_version_mismatch(): void
    {
        $entry = $this->schedule->entries()->first();
        $response = $this->deleteJson(
            "/api/schedules/{$this->schedule->id}/entries/{$entry->id}",
            [],
            ['X-Schedule-Version' => '2020-01-01T00:00:00.000000Z']
        );

        $response->assertStatus(409);
        $this->assertDatabaseHas('schedule_entries', ['id' => $entry->id]);
    }

    /**
     * A course whose assignment declares practical_in_labs=false generates
     * lab blocks parked in halls; moving one within halls must not be
     * refused by the session type alone.
     */
    private function seedAssignment(bool $practicalInLabs): void
    {
        $plan = StudyPlane::create([
            'name_en' => 'Manual Ops Plan',
            'name_ar' => 'خطة',
            'academic_id' => $this->academic->id,
            'academicLevel' => 2,
            'expected_students' => 60,
        ]);
        CourseAssignment::create([
            'study_plan_id' => $plan->id,
            'course_id' => $this->course->id,
            'lecture_groups' => 1,
            'lab_groups' => 2,
            'practical_in_labs' => $practicalInLabs,
        ]);
    }

    public function test_move_lab_within_halls_for_practical_in_halls_course(): void
    {
        $this->seedAssignment(practicalInLabs: false);
        $entry = $this->schedule->entries()->create([
            'entry_kind' => 'course',
            'course_ids' => [$this->course->id],
            'session_type' => 'lab',
            'group_number' => 1,
            'total_groups' => 2,
            'hall_id' => $this->hall->id,
            'lap_id' => null,
            'lecturer_id' => $this->lecturer->id,
            'Day' => 'monday',
            'startTime' => '09:00',
            'endTime' => '11:00',
            'student_count' => 25,
            'academic_ids' => [$this->academic->id],
            'academic_levels' => [2],
            'department_ids' => [$this->department->id],
        ]);
        $this->fakeEngineOk();

        $response = $this->patchJson(
            "/api/schedules/{$this->schedule->id}/entries/{$entry->id}",
            ['day' => 'tuesday', 'start_time' => '13:00', 'end_time' => '15:00', 'hall_id' => $this->hall->id]
        );

        $response->assertStatus(200);
        $this->assertDatabaseHas('schedule_entries', [
            'id' => $entry->id,
            'Day' => 'tuesday',
            'hall_id' => $this->hall->id,
            'hall_name' => 'Manual Ops Hall',
        ]);
    }

    public function test_move_lab_to_hall_is_refused_when_the_course_requires_labs(): void
    {
        $this->seedAssignment(practicalInLabs: true);
        $entry = $this->schedule->entries()->where('session_type', 'lab')->first();

        $response = $this->patchJson(
            "/api/schedules/{$this->schedule->id}/entries/{$entry->id}",
            ['day' => 'tuesday', 'start_time' => '13:00', 'end_time' => '15:00', 'hall_id' => $this->hall->id]
        );

        $response->assertStatus(422);
        $this->assertSame('A lab session must be placed in a lab, not a hall.', $response->json('message'));
        // The room pre-check refuses before the engine is ever consulted.
        Http::assertNothingSent();
    }

    public function test_move_within_the_current_room_kind_is_always_accepted(): void
    {
        // Drifted data: the assignment demands labs but generation parked the
        // block in a hall. Its current room kind stays movable either way —
        // the engine is the authority on whether the move conflicts.
        $this->seedAssignment(practicalInLabs: true);
        $entry = $this->schedule->entries()->create([
            'entry_kind' => 'course',
            'course_ids' => [$this->course->id],
            'session_type' => 'lab',
            'group_number' => 1,
            'total_groups' => 2,
            'hall_id' => $this->hall->id,
            'lap_id' => null,
            'lecturer_id' => $this->lecturer->id,
            'Day' => 'monday',
            'startTime' => '09:00',
            'endTime' => '11:00',
            'student_count' => 25,
            'academic_ids' => [$this->academic->id],
            'academic_levels' => [2],
            'department_ids' => [$this->department->id],
        ]);
        $this->fakeEngineOk();

        $response = $this->patchJson(
            "/api/schedules/{$this->schedule->id}/entries/{$entry->id}",
            ['day' => 'tuesday', 'start_time' => '13:00', 'end_time' => '15:00', 'hall_id' => $this->hall->id]
        );

        $response->assertStatus(200);
    }
}
