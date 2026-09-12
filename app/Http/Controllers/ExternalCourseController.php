<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExternalCourseRequest;
use App\Http\Resources\ExternalCourseResource;
use App\Models\ExternalCourse;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExternalCourseController extends Controller
{
    use ApiResponseTrait;

    /**
     * List external courses, or fetch a specific set with ?ids[]=1&ids[]=2
     * (the generation engine's selection fetch). Both always include the
     * eligible labs and staff distributions generation needs.
     */
    public function index(Request $request)
    {
        $query = ExternalCourse::with(['eligibleLabs', 'staffAssignments.staff'])
            ->latest('id');

        if ($request->filled('ids')) {
            $query->whereIn('id', $request->query('ids'));
        }

        return $this->ApiResponse(
            ExternalCourseResource::collection($query->get()),
            'get external courses successfully',
            200
        );
    }

    public function store(StoreExternalCourseRequest $request)
    {
        $course = DB::transaction(function () use ($request) {
            $course = ExternalCourse::create($this->courseAttributes($request));

            $this->syncRelations($course, $request);

            return $course;
        });

        return $this->ApiResponse(
            new ExternalCourseResource($course->load(['eligibleLabs', 'staffAssignments.staff'])),
            'created external course successfully',
            201
        );
    }

    public function show($id)
    {
        $course = ExternalCourse::with(['eligibleLabs', 'staffAssignments.staff'])->findOrFail($id);

        return $this->ApiResponse(
            new ExternalCourseResource($course),
            'get external course successfully',
            200
        );
    }

    public function update(StoreExternalCourseRequest $request, $id)
    {
        $course = ExternalCourse::findOrFail($id);

        DB::transaction(function () use ($course, $request) {
            $course->update($this->courseAttributes($request));

            $this->syncRelations($course, $request);
        });

        return $this->ApiResponse(
            new ExternalCourseResource($course->load(['eligibleLabs', 'staffAssignments.staff'])),
            'updated external course successfully',
            200
        );
    }

    /**
     * Deleting is always possible: schedule entries carry baked snapshots of
     * the course's display fields (resolved at their write time), so existing
     * schedules keep rendering their history — the entry's external_course_id
     * simply dangles, with no FK to restrict the delete.
     */
    public function destroy($id)
    {
        $course = ExternalCourse::findOrFail($id);

        $course->delete();

        return $this->ApiResponse(null, 'deleted external course successfully', 200);
    }

    private function courseAttributes(StoreExternalCourseRequest $request): array
    {
        $labGroups = (int) $request->input('lab_groups');
        $lectureGroups = (int) $request->input('lecture_groups');

        return [
            'code' => $request->input('code'),
            'name_en' => $request->input('name_en'),
            'name_ar' => $request->input('name_ar'),
            'requesting_entity_en' => $request->input('requesting_entity_en'),
            'requesting_entity_ar' => $request->input('requesting_entity_ar'),
            'lab_groups' => $labGroups,
            'lab_students_per_group' => $labGroups > 0 ? $request->input('lab_students_per_group') : null,
            'lecture_groups' => $lectureGroups,
            'lecture_students_per_group' => $lectureGroups > 0 ? $request->input('lecture_students_per_group') : null,
            'lecture_venue' => $lectureGroups > 0 ? $request->input('lecture_venue') : null,

            // Scheduling configuration. Present components default absent
            // hours to the historical 2h; an absent component stores null —
            // never a leftover value that would block removing it later.
            'lab_session_hours' => $labGroups > 0 ? (int) ($request->input('lab_session_hours') ?? 2) : null,
            'lecture_session_hours' => $lectureGroups > 0 ? (int) ($request->input('lecture_session_hours') ?? 2) : null,
            'lab_time_slots' => $labGroups > 0 ? $request->input('lab_time_slots') : null,
            'lecture_time_slots' => $lectureGroups > 0 ? $request->input('lecture_time_slots') : null,

            // Reserved-period override: only this course's assigned staff may
            // sit in the reserved period (see the engine's block flag).
            'allow_reserved_period' => $request->boolean('allow_reserved_period'),
        ];
    }

    /**
     * Replace the eligible-lab pivot and the staff distribution wholesale —
     * matching how LapController rewrites availability on update.
     */
    private function syncRelations(ExternalCourse $course, StoreExternalCourseRequest $request): void
    {
        $course->eligibleLabs()->sync($request->input('eligible_lab_ids', []) ?? []);

        $course->staffAssignments()->delete();

        foreach ($request->input('staff', []) ?? [] as $assignment) {
            $course->staffAssignments()->create([
                'staff_id' => (int) $assignment['staff_id'],
                'role' => $assignment['role'],
                'num_of_groups' => (int) $assignment['num_of_groups'],
            ]);
        }
    }
}
