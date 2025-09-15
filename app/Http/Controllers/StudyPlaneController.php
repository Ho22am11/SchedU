<?php

namespace App\Http\Controllers;

use App\Models\StudyPlane;
use App\Http\Controllers\Controller;
use App\Http\Resources\StudyplanResource;
use App\Models\CourseAssignment;
use App\Models\LecturerAssignment;
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

    public function store(Request $request)
    {
        // Validate course assignments
        $courseAssignmentErrors = $this->validateCourseAssignments($request['courseAssignments'] ?? []);
        if (!empty($courseAssignmentErrors)) {
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

                if (!empty($caData['preferredLabs'])) {
                    $courseAssignment->preferredLabs()->attach($caData['preferredLabs']);
                }

                if (!empty($caData['lecturers'])) {
                    foreach ($caData['lecturers'] as $lectData) {
                        LecturerAssignment::create([
                            'course_assignment_id' => $courseAssignment->id,
                            'lecturer_id' => $lectData['staffId'],
                            'num_groups' => $lectData['numGroups'],
                            'type' => 'lecturer',
                        ]);
                    }
                }

                if (!empty($caData['teachingAssistants'])) {
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
        if (!empty($courseAssignmentErrors)) {
            return $this->ApiResponse($courseAssignmentErrors, 'Validation failed', 422);
        }

        DB::transaction(function () use ($request, $id, &$studyPlan) {
            $studyPlan = StudyPlane::findOrFail($id);

            $studyPlan->update([
                'name_en' => $request['nameEn'],
                'name_ar' => $request['nameAr'],
                'academic_id' => $request['academicListId'],
                'academicLevel' => $request['academicLevel'],
                'expected_students' => $request['expectedStudents'],
            ]);

            $studyPlan->courseAssignments()->delete();

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

                if (!empty($caData['preferredLabs'])) {
                    $courseAssignment->preferredLabs()->attach($caData['preferredLabs']);
                }

                if (!empty($caData['lecturers'])) {
                    foreach ($caData['lecturers'] as $lectData) {
                        LecturerAssignment::create([
                            'course_assignment_id' => $courseAssignment->id,
                            'lecturer_id' => $lectData['staffId'],
                            'num_groups' => $lectData['numGroups'],
                            'type' => 'lecturer',
                        ]);
                    }
                }

                if (!empty($caData['teachingAssistants'])) {
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
        $studyPlan->delete();
        return $this->ApiResponse(null, 'deleted study plan successfully', 200);
    }

}
