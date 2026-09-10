<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreScheduleRequest;
use App\Http\Resources\ScheduleResource;
use App\Models\Hall;
use App\Models\Lap;
use App\Models\Lecturer;
use App\Models\Schedule;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduleController extends Controller
{
    use ApiResponseTrait;

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

            return $schedule;
        });

        $schedule = Schedule::with(['entries.lecturer.academicDegree', 'entries.externalCourse'])
            ->findOrFail($schedule->id);

        return $this->ApiResponse(
            new ScheduleResource($schedule->load('entries')),
            'Schedule stored successfully',
            201
        );
    }

    public function generationContext($id)
    {
        $schedule = Schedule::with(['entries', 'entries.externalCourse:id,lecture_venue'])
            ->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $schedule->id,
                'available_hall_ids' => $schedule->available_hall_ids,
                'available_lab_ids' => $schedule->available_lab_ids,
                'entries' => $schedule->entries->map(function ($entry) {
                    return [
                        'id' => $entry->id,
                        'entry_kind' => $entry->entry_kind,
                        'external_course_id' => $entry->external_course_id,
                        // "ours"|"external" on external entries so the engine
                        // can tell a roomless external-venue lecture from a
                        // hall booking; null for local course entries.
                        'lecture_venue' => $entry->externalCourse?->lecture_venue,
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
     * Create the schedule's entries, recording the room and lecturer display
     * names as they are right now. Schedule history renders from these
     * snapshots, so later renames or deletions never rewrite the past.
     *
     * External entries (entry_kind = "external") carry no local course,
     * academic, department, or staff data: their lecturer is nullable (no
     * snapshot when absent) and their room columns follow the component
     * rules — labs book a lab, lectures a hall, external-venue lectures
     * neither. Identity lives in external_course_id; the course's own
     * display data always comes from its live row, which the restricting FK
     * keeps in place as long as history refers to it.
     */
    private function createEntriesWithSnapshots(Schedule $schedule, array $entries): void
    {
        $hallNames = Hall::whereIn('id', collect($entries)->pluck('hall_id')->filter())
            ->pluck('name', 'id');
        $lapNames = Lap::whereIn('id', collect($entries)->pluck('lab_id')->filter())
            ->pluck('name', 'id');
        $lecturers = Lecturer::whereIn('id', collect($entries)->pluck('lecturer_id')->filter())
            ->get(['id', 'name', 'name_ar'])
            ->keyBy('id');

        foreach ($entries as $entry) {
            $isExternal = ($entry['entry_kind'] ?? 'course') === 'external';
            $lecturer = $lecturers->get($entry['lecturer_id'] ?? null);

            $schedule->entries()->create([
                'entry_kind' => $isExternal ? 'external' : 'course',
                'external_course_id' => $isExternal ? $entry['external_course_id'] : null,
                'course_ids' => $entry['course_ids'] ?? [],
                'session_type' => $entry['session_type'],
                'group_number' => $entry['group_info']['group_number'],
                'total_groups' => $entry['group_info']['total_groups'],
                'hall_id' => $entry['hall_id'] ?? null,
                'hall_name' => $hallNames[$entry['hall_id'] ?? null] ?? null,
                'lap_id' => $entry['lab_id'] ?? null,     // JSON field is lab_id, the DB column is lap_id
                'lap_name' => $lapNames[$entry['lab_id'] ?? null] ?? null,
                'lecturer_id' => $entry['lecturer_id'] ?? null,
                'lecturer_name' => $lecturer?->name,
                'lecturer_name_ar' => $lecturer?->name_ar,
                'Day' => $entry['time_slot']['day'],
                'startTime' => $entry['time_slot']['start_time'],
                'endTime' => $entry['time_slot']['end_time'],
                'student_count' => $entry['student_count'],
                'academic_ids' => $entry['academic_ids'] ?? [],
                'academic_levels' => $entry['academic_levels'] ?? [],
                'department_ids' => $entry['department_ids'] ?? [],
            ]);
        }
    }
}
