<?php

namespace App\Http\Controllers;

use App\Http\Resources\AcademicResource;
use App\Http\Resources\DepartmentResource;
use App\Models\Academic;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AcademicController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request)
    {
        $academics = Academic::with(['department', 'courses'])->get();

        $data = $academics->map(function ($academic) use ($request) {
            $locale = $request->header('Accept-Language', 'en');

            return [
                'id' => $academic->id,
                'name' => $locale === 'ar' ? $academic->name_ar : $academic->name,
                'nameEn' => $academic->name,
                'nameAr' => $academic->name_ar,
                'department' => new DepartmentResource($academic->department),
                'number_of_courses' => $academic->courses->count(),
            ];
        });

        return $this->ApiResponse($data, 'get academics successfully', 200);

    }

    public function store(Request $request)
    {
        DB::transaction(function () use ($request, &$academic) {
            $academic = Academic::create([
                'name' => $request['nameEn'],
                'name_ar' => $request['nameAr'],
                'department_id' => $request['departmentId'],
            ]);

            foreach ($request['courses'] as $courseData) {
                Course::create([
                    'code' => $courseData['code'],
                    'name_en' => $courseData['nameEn'],
                    'name_ar' => $courseData['nameAr'],
                    'lecture_hours' => $courseData['lectureHours'],
                    'practical_hours' => $courseData['practicalHours'],
                    'credit_hours' => $courseData['creditHours'],
                    'practical_components' => $courseData['preRequisiteCourseCode'] ?? '',
                    'academic_id' => $academic->id,
                ]);
            }
        });

        $academicWithCourses = Academic::find($academic->id);

        return $this->ApiResponse(new AcademicResource($academicWithCourses), 'get academics successfully', 200);

    }

    public function show($id)
    {
        $data = Academic::with('courses')->find($id);

        return $this->ApiResponse(new AcademicResource($data), 'showed academics successfully', 200);

    }

    public function update(Request $request, $id)
    {
        $academic = Academic::with('courses')->findOrFail($id);
        $existingCourses = $academic->courses->keyBy('id');
        $incomingCourseIds = collect($request->input('courses', []))
            ->map(fn (array $courseData) => $courseData['courseId'] ?? $courseData['id'] ?? null)
            ->filter()
            ->map(fn ($courseId) => (int) $courseId)
            ->values();

        if ($incomingCourseIds->count() !== $incomingCourseIds->unique()->count()) {
            return $this->ApiResponse(null, 'A course can only appear once in an academic list.', 422);
        }

        $unknownCourseIds = $incomingCourseIds->diff($existingCourses->keys());
        if ($unknownCourseIds->isNotEmpty()) {
            return $this->ApiResponse(null, 'One or more courses do not belong to this academic list.', 422);
        }

        $removedCourseIds = $existingCourses->keys()->diff($incomingCourseIds)->all();
        $removedAssignmentIds = CourseAssignment::query()
            ->whereIn('course_id', $removedCourseIds)
            ->pluck('id')
            ->all();
        $dependents = CourseAssignment::query()
            ->whereIn('common_course_id', $removedAssignmentIds)
            ->with(['studyPlan', 'course'])
            ->get();

        if ($dependents->isNotEmpty()) {
            return $this->ApiResponse([
                'dependents' => $dependents->map(fn (CourseAssignment $assignment) => [
                    'studyPlanId' => $assignment->study_plan_id,
                    'studyPlanName' => $assignment->studyPlan?->name_ar,
                    'courseId' => $assignment->course_id,
                    'courseCode' => $assignment->course?->code,
                ])->values(),
            ], 'This action would remove a common-course parent. Reassign its dependent courses or make them independent first.', 422);
        }

        DB::transaction(function () use ($request, $academic, $existingCourses) {
            $remainingCourses = $existingCourses;

            $academic->update([
                'name' => $request['nameEn'],
                'name_ar' => $request['nameAr'],
                'department_id' => $request['departmentId'],
            ]);

            foreach ($request['courses'] as $courseData) {
                $courseId = $courseData['courseId'] ?? $courseData['id'] ?? null;
                $courseAttributes = [
                    'code' => $courseData['code'],
                    'name_en' => $courseData['nameEn'],
                    'name_ar' => $courseData['nameAr'],
                    'lecture_hours' => $courseData['lectureHours'],
                    'practical_hours' => $courseData['practicalHours'],
                    'credit_hours' => $courseData['creditHours'],
                    'practical_components' => $courseData['preRequisiteCourseCode'] ?? '',
                ];

                if ($courseId) {
                    $remainingCourses->pull((int) $courseId)->update($courseAttributes);

                    continue;
                }

                Course::create($courseAttributes + ['academic_id' => $academic->id]);
            }

            $remainingCourses->each->delete();
        });

        $updatedAcademic = Academic::with('courses')->findOrFail($id);

        return $this->ApiResponse(new AcademicResource($updatedAcademic), 'Updated academics successfully', 200);
    }

    public function destroy($id)
    {
        Academic::destroy($id);

        return $this->ApiResponse(null, 'delete academics successfully', 200);

    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:academics,id'],
        ]);

        DB::transaction(function () use ($validated) {
            Academic::destroy($validated['ids']);
        });

        return $this->ApiResponse(null, 'deleted academics successfully', 200);
    }
}
