<?php

namespace App\Http\Controllers;

use App\Http\Resources\StudyplanResource;
use App\Models\CourseAssignment;
use App\Models\LecturerAssignment;
use App\Models\StudyPlane;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudyPlaneController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request)
    {
        $studyPlans = StudyPlane::with('academic')->get();

        return $this->ApiResponse(StudyplanResource::collection($studyPlans), 'get study plans successfully', 200);
    }

    public function create()
    {
        //
    }

    private function validateCourseAssignments($courseAssignments)
    {
        $errors = [];

        foreach ($courseAssignments as $index => $caData) {
            $isCommon = $caData['isCommon'] ?? false;

            // Validation rules that depend on isCommon flag
            if ($isCommon) {
                // For common courses: commonStudyPlanId and commonCourseId are required
                // lecture_groups and lecturers are optional
                if (empty($caData['commonStudyPlanId'])) {
                    $errors["courseAssignments.{$index}.commonStudyPlanId"] = 'Common study plan ID is required when course is common.';
                }
                if (empty($caData['commonCourseId'])) {
                    $errors["courseAssignments.{$index}.commonCourseId"] = 'Common course ID is required when course is common.';
                }
            } else {
                // For non-common courses: lecture_groups and lecturers are required
                // commonStudyPlanId and commonCourseId should be null
                if (empty($caData['lectureGroups']) || $caData['lectureGroups'] < 1) {
                    $errors["courseAssignments.{$index}.lectureGroups"] = 'Lecture groups must be at least 1 for non-common courses.';
                }
                if (empty($caData['lecturers'])) {
                    $errors["courseAssignments.{$index}.lecturers"] = 'At least one lecturer is required for non-common courses.';
                }
            }

            // Validate lab groups and teaching assistants relationship
            $labGroups = $caData['labGroups'] ?? 0;
            if ($labGroups > 0 && empty($caData['teachingAssistants'])) {
                $errors["courseAssignments.{$index}.teachingAssistants"] = 'Teaching assistants are required when lab groups are specified.';
            }
        }

        return $errors;
    }

    private function saveCourseAssignment(CourseAssignment $courseAssignment, array $data): void
    {
        $courseAssignment->update([
            'lecture_groups' => $data['lectureGroups'] ?? 0,
            'lab_groups' => $data['labGroups'] ?? 0,
            'is_common' => $data['isCommon'] ?? false,
            'practical_in_labs' => $data['practicalInLabs'] ?? true,
            'common_study_plan_id' => $data['commonStudyPlanId'] ?? null,
            'common_course_id' => $data['commonCourseId'] ?? null,
        ]);

        $courseAssignment->preferredLabs()->sync($data['preferredLabs'] ?? []);
        $courseAssignment->lecturerAssignments()->delete();

        foreach ($data['lecturers'] ?? [] as $lecturer) {
            LecturerAssignment::create([
                'course_assignment_id' => $courseAssignment->id,
                'lecturer_id' => $lecturer['staffId'],
                'num_groups' => $lecturer['numGroups'],
                'type' => 'lecturer',
            ]);
        }

        foreach ($data['teachingAssistants'] ?? [] as $assistant) {
            LecturerAssignment::create([
                'course_assignment_id' => $courseAssignment->id,
                'lecturer_id' => $assistant['staffId'],
                'num_groups' => $assistant['numGroups'],
                'type' => 'teaching_assistant',
            ]);
        }
    }

    private function commonDependentsForAssignmentIds(array $assignmentIds, array $excludedPlanIds = [])
    {
        if ($assignmentIds === []) {
            return collect();
        }

        return CourseAssignment::query()
            ->whereIn('common_course_id', $assignmentIds)
            ->when($excludedPlanIds !== [], fn ($query) => $query->whereNotIn('study_plan_id', $excludedPlanIds))
            ->with(['studyPlan', 'course'])
            ->get();
    }

    private function commonDependencyResponse($dependents)
    {
        return $this->ApiResponse([
            'dependents' => $dependents->map(fn (CourseAssignment $assignment) => [
                'studyPlanId' => $assignment->study_plan_id,
                'studyPlanName' => $assignment->studyPlan?->name_ar,
                'courseId' => $assignment->course_id,
                'courseCode' => $assignment->course?->code,
            ])->values(),
        ], 'This action would remove a common-course parent. Reassign its dependent courses or make them independent first.', 422);
    }

    public function store(Request $request)
    {
        // Validate course assignments
        $courseAssignmentErrors = $this->validateCourseAssignments($request['courseAssignments'] ?? []);
        if (! empty($courseAssignmentErrors)) {
            return $this->ApiResponse($courseAssignmentErrors, 'Validation failed', 422);
        }

        DB::transaction(function () use ($request, &$studyPlan) {
            $studyPlan = StudyPlane::create([
                'name_en' => $request['nameEn'],
                'name_ar' => $request['nameAr'],
                'academic_id' => $request['academicListId'],
                'academicLevel' => $request['academicLevel'],
                'expected_students' => $request['expectedStudents'],
            ]);

            foreach ($request['courseAssignments'] as $caData) {
                $courseAssignment = CourseAssignment::create([
                    'study_plan_id' => $studyPlan->id,
                    'course_id' => $caData['courseId'],
                    'lecture_groups' => $caData['lectureGroups'] ?? 0,
                    'lab_groups' => $caData['labGroups'] ?? 0,
                    'is_common' => $caData['isCommon'] ?? false,
                    'practical_in_labs' => $caData['practicalInLabs'] ?? true,
                    'common_study_plan_id' => $caData['commonStudyPlanId'] ?? null,
                    'common_course_id' => $caData['commonCourseId'] ?? null,
                ]);

                if (! empty($caData['preferredLabs'])) {
                    $courseAssignment->preferredLabs()->attach($caData['preferredLabs']);
                }

                if (! empty($caData['lecturers'])) {
                    foreach ($caData['lecturers'] as $lectData) {
                        LecturerAssignment::create([
                            'course_assignment_id' => $courseAssignment->id,
                            'lecturer_id' => $lectData['staffId'],
                            'num_groups' => $lectData['numGroups'],
                            'type' => 'lecturer',
                        ]);
                    }
                }

                if (! empty($caData['teachingAssistants'])) {
                    foreach ($caData['teachingAssistants'] as $taData) {
                        LecturerAssignment::create([
                            'course_assignment_id' => $courseAssignment->id,
                            'lecturer_id' => $taData['staffId'],
                            'num_groups' => $taData['numGroups'],
                            'type' => 'teaching_assistant',
                        ]);
                    }
                }
            }
        });

        $studyPlan = StudyPlane::with([
            'academic',
            'courseAssignments',
            'courseAssignments.course',
            'courseAssignments.lecturerAssignments',
            'courseAssignments.preferredLabs',
            'courseAssignments.lecturerAssignments.lecturer',
            'courseAssignments.lecturerAssignments.lecturer.academicDegree',
            'courseAssignments.commonStudyPlan',
            'courseAssignments.commonCourse',
            'courseAssignments.commonCourse.course',
        ])->find($studyPlan->id);

        return $this->ApiResponse(new StudyplanResource($studyPlan), 'stored study plans successfully', 200);

    }

    public function show($id)
    {
        $studyPlan = StudyPlane::with([
            'academic',
            'courseAssignments',
            'courseAssignments.course',
            'courseAssignments.preferredLabs',
            'courseAssignments.lecturerAssignments',
            'courseAssignments.lecturerAssignments.lecturer',
            'courseAssignments.lecturerAssignments.lecturer.academicDegree',
            'courseAssignments.commonStudyPlan',
            'courseAssignments.commonCourse',
            'courseAssignments.commonCourse.course',
        ])->findOrFail($id);

        return $this->ApiResponse(new StudyplanResource($studyPlan), 'show study plan successfully', 200);
    }

    public function update(Request $request, $id)
    {

        $courseAssignmentErrors = $this->validateCourseAssignments($request['courseAssignments'] ?? []);
        if (! empty($courseAssignmentErrors)) {
            return $this->ApiResponse($courseAssignmentErrors, 'Validation failed', 422);
        }

        $studyPlan = StudyPlane::findOrFail($id);
        $existingAssignments = $studyPlan->courseAssignments()->get()->keyBy('course_id');
        $incomingCourseIds = collect($request['courseAssignments'])->pluck('courseId')->map(fn ($id) => (int) $id)->all();
        $removedAssignmentIds = $existingAssignments
            ->reject(fn (CourseAssignment $assignment) => in_array($assignment->course_id, $incomingCourseIds, true))
            ->pluck('id')
            ->all();
        $dependents = $this->commonDependentsForAssignmentIds($removedAssignmentIds, [$studyPlan->id]);
        if ($dependents->isNotEmpty()) {
            return $this->commonDependencyResponse($dependents);
        }

        DB::transaction(function () use ($request, $studyPlan, $existingAssignments) {
            $remainingAssignments = $existingAssignments;

            $studyPlan->update([
                'name_en' => $request['nameEn'],
                'name_ar' => $request['nameAr'],
                'academic_id' => $request['academicListId'],
                'academicLevel' => $request['academicLevel'],
                'expected_students' => $request['expectedStudents'],
            ]);

            foreach ($request['courseAssignments'] as $caData) {
                $courseAssignment = $remainingAssignments->pull((int) $caData['courseId']);
                if (! $courseAssignment) {
                    $courseAssignment = CourseAssignment::create([
                        'study_plan_id' => $studyPlan->id,
                        'course_id' => $caData['courseId'],
                    ]);
                }

                $this->saveCourseAssignment($courseAssignment, $caData);
            }

            $remainingAssignments->each->delete();
        });

        $studyPlan = StudyPlane::with([
            'academic',
            'courseAssignments',
            'courseAssignments.course',
            'courseAssignments.preferredLabs',
            'courseAssignments.lecturerAssignments',
            'courseAssignments.lecturerAssignments.lecturer',
            'courseAssignments.lecturerAssignments.lecturer.academicDegree',
            'courseAssignments.commonStudyPlan',
            'courseAssignments.commonCourse',
            'courseAssignments.commonCourse.course',
        ])->find($id);

        return $this->ApiResponse(new StudyplanResource($studyPlan), 'updated study plan successfully', 200);
    }

    public function destroy($id)
    {
        $studyPlan = StudyPlane::findOrFail($id);
        $dependents = $this->commonDependentsForAssignmentIds(
            $studyPlan->courseAssignments()->pluck('id')->all(),
            [$studyPlan->id]
        );
        if ($dependents->isNotEmpty()) {
            return $this->commonDependencyResponse($dependents);
        }

        $studyPlan->delete();

        return $this->ApiResponse(null, 'deleted study plan successfully', 200);
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:study_planes,id'],
        ]);

        $assignmentIds = CourseAssignment::query()
            ->whereIn('study_plan_id', $validated['ids'])
            ->pluck('id')
            ->all();
        $dependents = $this->commonDependentsForAssignmentIds($assignmentIds, $validated['ids']);
        if ($dependents->isNotEmpty()) {
            return $this->commonDependencyResponse($dependents);
        }

        DB::transaction(function () use ($validated) {
            StudyPlane::destroy($validated['ids']);
        });

        return $this->ApiResponse(null, 'deleted study plans successfully', 200);
    }
}
