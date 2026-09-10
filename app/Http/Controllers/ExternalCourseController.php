<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExternalCourseRequest;
use App\Http\Resources\ExternalCourseResource;
use App\Models\ExternalCourse;
use App\Models\ScheduleEntry;
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
     * An external course referenced by schedule history cannot be deleted —
     * the FK restricts and this responds 409 with the usage count so the UI
     * can explain it. Editing stays possible for future generations.
     */
    public function destroy($id)
    {
        $course = ExternalCourse::findOrFail($id);

        $sessionCount = ScheduleEntry::where('external_course_id', $course->id)->count();
        if ($sessionCount > 0) {
            return response()->json([
                'status' => false,
                'message' => "Cannot delete this external course: {$sessionCount} schedule session(s) still reference it. Schedules keep their history, so the course stays available for editing and future generations.",
                'blocked_ids' => [$course->id],
                'errors' => ['references' => [$course->id => $sessionCount]],
            ], 409);
        }

        $course->delete();

        return $this->ApiResponse(null, 'deleted external course successfully', 200);
    }

    private function courseAttributes(StoreExternalCourseRequest $request): array
    {
        return [
            'code' => $request->input('code'),
            'name_en' => $request->input('name_en'),
            'name_ar' => $request->input('name_ar'),
            'requesting_entity_en' => $request->input('requesting_entity_en'),
            'requesting_entity_ar' => $request->input('requesting_entity_ar'),
            'lab_groups' => (int) $request->input('lab_groups'),
            'lab_students_per_group' => $request->input('lab_students_per_group'),
            'lecture_groups' => (int) $request->input('lecture_groups'),
            'lecture_students_per_group' => $request->input('lecture_students_per_group'),
            'lecture_venue' => $request->input('lecture_venue'),

            // Scheduling configuration; absent hours mean the historical 2h.
            'lab_session_hours' => (int) ($request->input('lab_session_hours') ?? 2),
            'lecture_session_hours' => (int) ($request->input('lecture_session_hours') ?? 2),
            'lab_time_slots' => $request->input('lab_time_slots'),
            'lecture_time_slots' => $request->input('lecture_time_slots'),
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
