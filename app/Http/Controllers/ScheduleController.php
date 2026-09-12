<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreScheduleRequest;
use App\Http\Resources\ScheduleResource;
use App\Models\Schedule;
use App\Models\ScheduleBlocker;
use App\Services\ScheduleEntryFactory;
use App\Traits\ApiResponseTrait;
use App\Traits\ValidatesBlockers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduleController extends Controller
{
    use ApiResponseTrait;
    use ValidatesBlockers;

    public function index()
    {
        $schedules = Schedule::latest()->get();

        return $this->ApiResponse(ScheduleResource::collection($schedules), 'schedule stored successffly', 201);

    }

    public function store(StoreScheduleRequest $request)
    {
        $schedule = DB::transaction(function () use ($request) {
            $schedule = Schedule::create([
                'nameEn' => $request->nameEn,
                'nameAr' => $request->nameAr,
                'source_schedule_id' => $request->source_schedule_id,
                'available_hall_ids' => $request->available_hall_ids,
                'available_lab_ids' => $request->available_lab_ids,
                ...$this->reservedPeriodAttributes($request->input('reserved_period')),
            ]);

            $this->createEntriesWithSnapshots($schedule, $request->schedule);

            // Bake the blockers the generation actually enforced — later
            // staff-page edits never rewrite the schedule's past.
            $this->createScheduleBlockers($schedule, (array) $request->input('blockers'));

            return $schedule;
        });

        $schedule = Schedule::with(['entries.lecturer.academicDegree', 'entries.externalCourse', 'blockers'])
            ->findOrFail($schedule->id);

        return $this->ApiResponse(
            new ScheduleResource($schedule->load('entries')),
            'Schedule stored successfully',
            201
        );
    }

    public function generationContext($id)
    {
        $schedule = Schedule::with(['entries', 'entries.externalCourse:id,lecture_venue', 'blockers'])
            ->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $schedule->id,
                'available_hall_ids' => $schedule->available_hall_ids,
                'available_lab_ids' => $schedule->available_lab_ids,
                // Baked blockers: the engine's editor validation enforces
                // these (not the live staff templates) so a change on the
                // staff page never moves this schedule's goalposts.
                'blockers' => $schedule->blockers->map(function ($blocker) {
                    return [
                        'lecturer_id' => $blocker->lecturer_id,
                        'day' => strtolower($blocker->day),
                        'start_time' => \Str::substr($blocker->startTime, 0, 5),
                        'end_time' => \Str::substr($blocker->endTime, 0, 5),
                        'label' => $blocker->label,
                    ];
                })->values(),
                'entries' => $schedule->entries->map(function ($entry) {
                    return [
                        'id' => $entry->id,
                        'entry_kind' => $entry->entry_kind,
                        'external_course_id' => $entry->external_course_id,
                        // "ours"|"external" on external entries so the engine
                        // can tell a roomless external-venue lecture from a
                        // hall booking; null for local course entries.
                        // Baked snapshot first, live relation as the legacy
                        // fallback — a deleted course must not break history.
                        'lecture_venue' => $entry->external_course_venue
                            ?? $entry->externalCourse?->lecture_venue,
                        'external_course_code' => $entry->external_course_code,
                        'external_course_name_en' => $entry->external_course_name_en,
                        'external_course_name_ar' => $entry->external_course_name_ar,
                        'course_ids' => $entry->course_ids,
                        'session_type' => $entry->session_type,
                        'group_number' => $entry->group_number,
                        'total_groups' => $entry->total_groups,
                        'hall_id' => $entry->hall_id,
                        'lab_id' => $entry->lap_id,
                        'lecturer_id' => $entry->lecturer_id,
                        'day' => strtolower($entry->Day),
                        'start_time' => \Str::substr($entry->startTime, 0, 5),
                        'end_time' => \Str::substr($entry->endTime, 0, 5),
                        'student_count' => $entry->student_count,
                        'academic_ids' => $entry->academic_ids,
                        'academic_levels' => $entry->academic_levels,
                        'department_ids' => $entry->department_ids,
                    ];
                })->values(),
            ],
        ]);
    }

    public function show(Request $request, $id)
    {
        $schedule = Schedule::with([
            'entries.lap',
            'entries.hall',
            'entries.lecturer.academicDegree',
            'entries.externalCourse',
            'blockers',
        ])->findOrFail($id);

        $staffId = $request->query('staff_id');
        $hallId = $request->query('hall_id');
        $labId = $request->query('lab_id');
        $academicListId = $request->query('academic_list_id');
        $academicLevel = $request->query('academic_level');
        $departmentId = $request->query('department_id');

        $filteredEntries = $schedule->entries->filter(function ($entry) use ($staffId, $hallId, $labId, $academicListId, $academicLevel, $departmentId) {
            // Check staff filter
            $staffMatch = ! $staffId || $entry->lecturer_id == $staffId;

            // Check hall filter
            $hallMatch = ! $hallId || $entry->hall_id == $hallId;

            // Check lab filter
            $labMatch = ! $labId || $entry->lap_id == $labId;

            // Check academic list filter (only check multiple IDs)
            $academicMatch = ! $academicListId ||
                (is_array($entry->academic_ids) && in_array((int) $academicListId, $entry->academic_ids));

            // Check academic level filter (only check multiple levels)
            $levelMatch = ! $academicLevel ||
                (is_array($entry->academic_levels) && in_array((int) $academicLevel, $entry->academic_levels));

            // Check department filter (only check multiple IDs)
            $departmentMatch = ! $departmentId ||
                (is_array($entry->department_ids) && in_array((int) $departmentId, $entry->department_ids));

            return $staffMatch && $hallMatch && $labMatch && $academicMatch && $levelMatch && $departmentMatch;
        });

        $schedule->setRelation('entries', $filteredEntries->values());

        return new ScheduleResource($schedule);
    }

    public function update(StoreScheduleRequest $request, $id)
    {
        $validated = $request->validated();

        $schedule = Schedule::findOrFail($id);

        $scheduleAttributes = [
            'nameEn' => $validated['nameEn'],
            'nameAr' => $validated['nameAr'],
        ];

        if (array_key_exists('reserved_period', $validated)) {
            $scheduleAttributes = [
                ...$scheduleAttributes,
                ...$this->reservedPeriodAttributes($validated['reserved_period']),
            ];
        }

        foreach (['available_hall_ids', 'available_lab_ids'] as $selectionKey) {
            if (array_key_exists($selectionKey, $validated)) {
                $scheduleAttributes[$selectionKey] = $validated[$selectionKey];
            }
        }

        DB::transaction(function () use ($schedule, $scheduleAttributes, $validated) {
            $schedule->update($scheduleAttributes);

            $schedule->entries()->delete();

            $this->createEntriesWithSnapshots($schedule, $validated['schedule']);
        });

        $schedule->load(['entries.lecturer.academicDegree', 'entries.externalCourse']);

        return $this->ApiResponse(
            new ScheduleResource($schedule),
            'Schedule updated successfully',
            200
        );
    }

    public function destroy(string $id)
    {
        $schedule = Schedule::findOrFail($id);
        $schedule->entries()->delete();
        Schedule::destroy($id);

        return $this->ApiResponse(
            null,
            'Schedule deleted successfully',
            200
        );

    }

    /**
     * Add a blocker to THIS schedule only — hand-placed from the editor's
     * staff-filtered view. It never touches the staff member's template
     * blockers, and it hard-blocks editor moves/swaps plus renders on the
     * person's exports from here on.
     */
    public function storeBlocker(Request $request, $scheduleId)
    {
        $schedule = Schedule::findOrFail($scheduleId);

        $validated = $request->validate([
            'lecturer_id' => ['required', 'integer', 'exists:lecturers,id'],
            'day' => ['required', 'string'],
            'startTime' => ['required', 'string'],
            'endTime' => ['required', 'string'],
            'label' => ['required', 'string', 'max:191'],
        ]);

        $this->validateBlockers([$validated], 'blocker');

        $duplicate = $schedule->blockers()
            ->where('lecturer_id', $validated['lecturer_id'])
            ->where('day', strtolower($validated['day']))
            ->where('startTime', $validated['startTime'])
            ->where('endTime', $validated['endTime'])
            ->exists();

        if ($duplicate) {
            return $this->ApiResponse(null, 'This slot is already blocked on this schedule.', 409);
        }

        $blocker = $schedule->blockers()->create([
            ...$validated,
            'day' => strtolower($validated['day']),
        ]);

        return $this->ApiResponse($blocker->only(['id', 'lecturer_id', 'day', 'startTime', 'endTime', 'label']), 'schedule blocker added successfully', 201);
    }

    /**
     * Remove a baked blocker from this schedule. The staff member's
     * template blockers are untouched.
     */
    public function destroyBlocker($scheduleId, $blockerId)
    {
        $blocker = ScheduleBlocker::where('schedule_id', $scheduleId)->findOrFail($blockerId);
        $blocker->delete();

        return $this->ApiResponse(null, 'schedule blocker removed successfully', 200);
    }

    private function reservedPeriodAttributes(?array $period): array
    {
        return [
            'reserved_period_day' => $period['day'] ?? null,
            'reserved_period_start_time' => $period['start_time'] ?? null,
            'reserved_period_end_time' => $period['end_time'] ?? null,
            'reserved_period_label_ar' => $period
                ? \App\Models\ScheduleSetting::RESERVED_PERIOD_LABEL_AR
                : null,
        ];
    }

    /**
     * Bake blockers carried by the generation request (the engine sends the
     * staff templates it actually enforced; the editor never sends any
     * here — its blockers go through the dedicated endpoints).
     */
    private function createScheduleBlockers(Schedule $schedule, array $blockers): void
    {
        if ($blockers === []) {
            return;
        }

        $this->validateBlockers($blockers, 'blockers');

        foreach ($blockers as $blocker) {
            $schedule->blockers()->create([
                'lecturer_id' => $blocker['lecturer_id'],
                'day' => strtolower($blocker['day']),
                'startTime' => $blocker['startTime'],
                'endTime' => $blocker['endTime'],
                'label' => $blocker['label'],
            ]);
        }
    }

    /**
     * Create the schedule's entries, recording the room and lecturer display
     * names as they are right now. Schedule history renders from these
     * snapshots, so later renames or deletions never rewrite the past.
     *
     * External entries (entry_kind = "external") carry no local course,
     * academic, department, or staff data: their lecturer is nullable (no
     * snapshot when absent) and their room columns follow the component
     * rules — labs book a lab, lectures a hall, external-venue lectures
     * neither. The external course's own display fields are baked the same
     * way, so history survives edits to — or deletion of — the course.
     */
    private function createEntriesWithSnapshots(Schedule $schedule, array $entries): void
    {
        $sources = ScheduleEntryFactory::nameSources($entries);

        foreach ($entries as $entry) {
            $schedule->entries()->create(
                ScheduleEntryFactory::attributes($entry, $sources)
            );
        }
    }
}
