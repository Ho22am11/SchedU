<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEntryRequest;
use App\Http\Requests\SwapEntryRequest;
use App\Http\Requests\UpdateEntryRequest;
use App\Http\Resources\EntryScheduleResource;
use App\Models\CourseAssignment;
use App\Models\Schedule;
use App\Models\ScheduleEntry;
use App\Services\EngineChangeValidator;
use App\Services\ScheduleEntryFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Targeted schedule entry edits (move / swap) backing the web editor.
 *
 * Every change is validated by the generation engine before it is written:
 * the engine owns the hard/soft constraint rules and is the single source of
 * truth, so persistence fails closed when the engine is unreachable.
 */
class EntryController extends Controller
{
    public function __construct(private EngineChangeValidator $engine) {}

    /**
     * Move an entry to another time slot and/or hall/lab.
     *
     * PATCH /api/schedules/{schedule}/entries/{entry}
     */
    public function update(UpdateEntryRequest $request, $schedule, $entry): JsonResponse
    {
        $schedule = Schedule::findOrFail($schedule);
        /** @var ScheduleEntry $entry */
        $entry = $schedule->entries()->findOrFail($entry);

        if ($response = $this->guardAgainstConcurrentEdit($request, $schedule)) {
            return $response;
        }

        $validated = $request->validated();

        if ($mismatch = $this->roomTypeMismatch($entry, $validated)) {
            return $this->reject($mismatch);
        }

        // External-venue lectures book no room — moving them moves time
        // only; a room appearing here would fake a hall booking.
        if ($this->isRoomless($entry)
            && (! empty($validated['hall_id']) || ! empty($validated['lab_id']))
        ) {
            return $this->reject('This session is hosted at an external venue and cannot be assigned a room.');
        }

        $change = [
            'type' => 'move',
            'entry_id' => $entry->id,
            'target' => [
                'day' => $validated['day'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
            ],
        ];
        if (! empty($validated['hall_id'])) {
            $change['room'] = ['type' => 'hall', 'id' => (int) $validated['hall_id']];
        } elseif (! empty($validated['lab_id'])) {
            $change['room'] = ['type' => 'lab', 'id' => (int) $validated['lab_id']];
        }

        if ($response = $this->rejectIfEngineRejects($schedule, $change)) {
            return $response;
        }

        $previousVersion = $this->currentVersion($schedule);

        [$entry, $newVersion] = DB::transaction(function () use ($entry, $schedule, $validated) {
            $entry->Day = $validated['day'];
            $entry->startTime = $validated['start_time'];
            $entry->endTime = $validated['end_time'];
            if (array_key_exists('hall_id', $validated) && ! empty($validated['hall_id'])) {
                $entry->hall_id = (int) $validated['hall_id'];
                $entry->lap_id = null;
                $entry->refreshRoomSnapshots();
            } elseif (array_key_exists('lab_id', $validated) && ! empty($validated['lab_id'])) {
                $entry->lap_id = (int) $validated['lab_id'];
                $entry->hall_id = null;
                $entry->refreshRoomSnapshots();
            }
            $entry->save();
            $schedule->touch();

            return [
                $entry->fresh(['lap', 'hall', 'lecturer.academicDegree']),
                $this->currentVersion($schedule->fresh()),
            ];
        });

        $this->engine->apply($schedule->id, $previousVersion, $change, $newVersion);

        return response()->json([
            'status' => true,
            'message' => 'Schedule entry moved successfully.',
            'data' => [
                'entry' => new EntryScheduleResource($entry),
                'updated_at' => $newVersion,
            ],
        ]);
    }

    /**
     * Swap day, time, and room between two entries of the same session type.
     *
     * POST /api/schedules/{schedule}/entries/{entry}/swap
     */
    public function swap(SwapEntryRequest $request, $schedule, $entry): JsonResponse
    {
        $schedule = Schedule::findOrFail($schedule);
        /** @var ScheduleEntry $entry */
        $entry = $schedule->entries()->findOrFail($entry);
        /** @var ScheduleEntry $target */
        $target = $schedule->entries()->findOrFail($request->validated('target_entry_id'));

        if ($target->id === $entry->id) {
            return $this->reject('An entry cannot be swapped with itself.');
        }
        if ($target->session_type !== $entry->session_type) {
            return $this->reject('Only entries of the same session type can be swapped.');
        }
        if ($this->isRoomless($entry) !== $this->isRoomless($target)) {
            return $this->reject('Only entries with the same room arrangement can be swapped: a roomless external-venue session cannot trade places with a roomed one.');
        }

        if ($response = $this->guardAgainstConcurrentEdit($request, $schedule)) {
            return $response;
        }

        $change = [
            'type' => 'swap',
            'entry_id' => $entry->id,
            'target_entry_id' => $target->id,
        ];

        if ($response = $this->rejectIfEngineRejects($schedule, $change)) {
            return $response;
        }

        $previousVersion = $this->currentVersion($schedule);

        [$entry, $target, $newVersion] = DB::transaction(function () use ($entry, $target, $schedule) {
            $placement = [$entry->Day, $entry->startTime, $entry->endTime, $entry->hall_id, $entry->lap_id];

            $entry->Day = $target->Day;
            $entry->startTime = $target->startTime;
            $entry->endTime = $target->endTime;
            $entry->hall_id = $target->hall_id;
            $entry->lap_id = $target->lap_id;
            $entry->refreshRoomSnapshots();

            $target->Day = $placement[0];
            $target->startTime = $placement[1];
            $target->endTime = $placement[2];
            $target->hall_id = $placement[3];
            $target->lap_id = $placement[4];
            $target->refreshRoomSnapshots();

            $entry->save();
            $target->save();
            $schedule->touch();

            $with = ['lap', 'hall', 'lecturer.academicDegree'];

            return [
                $entry->fresh($with),
                $target->fresh($with),
                $this->currentVersion($schedule->fresh()),
            ];
        });

        $this->engine->apply($schedule->id, $previousVersion, $change, $newVersion);

        return response()->json([
            'status' => true,
            'message' => 'Schedule entries swapped successfully.',
            'data' => [
                'entry' => new EntryScheduleResource($entry),
                'target_entry' => new EntryScheduleResource($target),
                'updated_at' => $newVersion,
            ],
        ]);
    }

    /**
     * Add a missing block (from the engine's pending-blocks scan) as a new
     * entry of this schedule.
     *
     * POST /api/schedules/{schedule}/entries
     */
    public function store(StoreEntryRequest $request, $schedule): JsonResponse
    {
        $schedule = Schedule::findOrFail($schedule);

        if ($response = $this->guardAgainstConcurrentEdit($request, $schedule)) {
            return $response;
        }

        $validated = $request->validated();

        $change = [
            'type' => 'add',
            'block_key' => $validated['block_key'],
            'target' => [
                'day' => $validated['day'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
            ],
        ];
        if (! empty($validated['hall_id'])) {
            $change['room'] = ['type' => 'hall', 'id' => (int) $validated['hall_id']];
        } elseif (! empty($validated['lab_id'])) {
            $change['room'] = ['type' => 'lab', 'id' => (int) $validated['lab_id']];
        }

        if ($response = $this->rejectIfEngineRejects($schedule, $change)) {
            return $response;
        }

        $previousVersion = $this->currentVersion($schedule);

        [$entry, $newVersion] = DB::transaction(function () use ($validated, $schedule) {
            // The factory consumes the engine's posted entry shape.
            $entryShape = [
                'entry_kind' => $validated['entry_kind'],
                'external_course_id' => $validated['external_course_id'] ?? null,
                'course_ids' => $validated['course_ids'] ?? [],
                'session_type' => $validated['session_type'],
                'group_info' => [
                    'group_number' => $validated['group_number'],
                    'total_groups' => $validated['total_groups'],
                ],
                'hall_id' => $validated['hall_id'] ?? null,
                'lab_id' => $validated['lab_id'] ?? null,
                'lecturer_id' => $validated['lecturer_id'] ?? null,
                'time_slot' => [
                    'day' => $validated['day'],
                    'start_time' => $validated['start_time'],
                    'end_time' => $validated['end_time'],
                ],
                'student_count' => $validated['student_count'],
                'academic_ids' => $validated['academic_ids'] ?? [],
                'academic_levels' => $validated['academic_levels'] ?? [],
                'department_ids' => $validated['department_ids'] ?? [],
            ];

            $sources = ScheduleEntryFactory::nameSources([$entryShape]);
            $entry = $schedule->entries()->create(
                ScheduleEntryFactory::attributes($entryShape, $sources)
            );
            $schedule->touch();

            return [
                $entry->fresh(['lap', 'hall', 'lecturer.academicDegree', 'externalCourse']),
                $this->currentVersion($schedule->fresh()),
            ];
        });

        $change['new_entry_id'] = $entry->id;
        $this->engine->apply($schedule->id, $previousVersion, $change, $newVersion);

        return response()->json([
            'status' => true,
            'message' => 'Schedule entry added successfully.',
            'data' => [
                'entry' => new EntryScheduleResource($entry),
                'updated_at' => $newVersion,
            ],
        ], 201);
    }

    /**
     * Remove an entry from the schedule. Deleting cannot create conflicts,
     * but the engine still gates the write (fail closed) and keeps its cached
     * state authoritative. The scan re-lists the block as missing afterwards.
     *
     * DELETE /api/schedules/{schedule}/entries/{entry}
     */
    public function destroy(Request $request, $schedule, $entry): JsonResponse
    {
        $schedule = Schedule::findOrFail($schedule);
        /** @var ScheduleEntry $entry */
        $entry = $schedule->entries()->findOrFail($entry);

        // A zero-entry schedule cannot be rebuilt by the engine, which would
        // brick every later editor call - the last entry stays.
        if ($schedule->entries()->count() <= 1) {
            return $this->reject('The last remaining entry of a schedule cannot be deleted.');
        }

        if ($response = $this->guardAgainstConcurrentEdit($request, $schedule)) {
            return $response;
        }

        $change = ['type' => 'remove', 'entry_id' => $entry->id];

        if ($response = $this->rejectIfEngineRejects($schedule, $change)) {
            return $response;
        }

        $previousVersion = $this->currentVersion($schedule);

        $newVersion = DB::transaction(function () use ($entry, $schedule) {
            $entry->delete();
            $schedule->touch();

            return $this->currentVersion($schedule->fresh());
        });

        $this->engine->apply($schedule->id, $previousVersion, $change, $newVersion);

        return response()->json([
            'status' => true,
            'message' => 'Schedule entry deleted successfully.',
            'data' => [
                'deleted' => true,
                'updated_at' => $newVersion,
            ],
        ]);
    }

    /**
     * When the caller passes X-Schedule-Version it must match the schedule's
     * current updated_at, otherwise the schedule changed underneath the editor.
     */
    private function guardAgainstConcurrentEdit($request, Schedule $schedule): ?JsonResponse
    {
        $version = $request->header('X-Schedule-Version');

        if ($version !== null && $version !== $this->currentVersion($schedule)) {
            return response()->json([
                'status' => false,
                'message' => 'The schedule was changed elsewhere. Refresh and try again.',
            ], 409);
        }

        return null;
    }

    /**
     * Room-kind rules for a move. Lectures are always in halls; lab sessions
     * follow the courses' practical_in_labs flag, not the session type alone:
     * courses with practical sections in halls generate lab blocks parked in
     * halls, and those must stay movable within halls. The entry's current
     * room kind is always accepted — generation already vetted it.
     */
    private function roomTypeMismatch(ScheduleEntry $entry, array $validated): ?string
    {
        if (! empty($validated['hall_id'])) {
            if ($entry->lap_id !== null
                && $entry->session_type === 'lab'
                && $this->practicalRequiresLabs($entry)
            ) {
                return 'A lab session must be placed in a lab, not a hall.';
            }
        }
        if (! empty($validated['lab_id']) && $entry->session_type !== 'lab') {
            return 'A lecture session must be placed in a hall, not a lab.';
        }

        return null;
    }

    /**
     * Whether this entry's courses declare practical sections in labs
     * (course_assignments.practical_in_labs) for the entry's academic
     * cohorts. When no assignment matches the cohorts — drifted plans, or a
     * common-course parent living on its own plan — any assignment for the
     * courses decides, and no assignment at all defaults to labs-only.
     */
    private function practicalRequiresLabs(ScheduleEntry $entry): bool
    {
        $courseIds = $entry->course_ids ?? [];
        if (! $courseIds) {
            return true;
        }

        $scoped = CourseAssignment::whereIn('course_id', $courseIds)
            ->whereHas('studyPlan', function ($query) use ($entry) {
                $query->whereIn('academic_id', $entry->academic_ids ?? [])
                    ->whereIn('academicLevel', $entry->academic_levels ?? []);
            });

        if ((clone $scoped)->exists()) {
            return (clone $scoped)->where('practical_in_labs', true)->exists();
        }

        if (CourseAssignment::whereIn('course_id', $courseIds)
            ->where('practical_in_labs', true)
            ->exists()) {
            return true;
        }

        // Assignments survive but all declare halls. With none left at all,
        // fail safe to labs-only.
        return CourseAssignment::whereIn('course_id', $courseIds)->exists();
    }

    /**
     * Roomless entries are external-venue lectures: they reserve time and
     * their lecturer's time but no room of ours. Local entries always carry
     * exactly one room.
     */
    private function isRoomless(ScheduleEntry $entry): bool
    {
        return $entry->hall_id === null && $entry->lap_id === null;
    }

    /**
     * Ask the engine. Any hard conflict, malformed change, or unreachable
     * engine refuses the write (fail closed).
     */
    private function rejectIfEngineRejects(Schedule $schedule, array $change): ?JsonResponse
    {
        $result = $this->engine->validate($schedule->id, $this->currentVersion($schedule), $change);

        if (! $result['available']) {
            return response()->json([
                'status' => false,
                'message' => 'Schedule editing is temporarily unavailable: the scheduling engine is not ready. Try again in a moment.',
                'engine_available' => false,
            ], 503);
        }

        if ($result['error'] !== null) {
            return $this->reject($result['error']);
        }

        if (! $result['ok']) {
            return $this->reject(
                'The change was rejected: it would create schedule conflicts.',
                $result['payload']
            );
        }

        return null;
    }

    private function currentVersion(Schedule $schedule): ?string
    {
        return $schedule->updated_at?->toISOString();
    }

    private function reject(string $message, array $extra = []): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
        ] + $extra, 422);
    }
}
