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
    use ApiResponseTrait ;
    public function index(Request $request)
    {
        $studyPlans = StudyPlane::with('academic')->get();
    
        $data = $studyPlans->map(function($plan) use ($request) {
            $locale = $request->header('Accept-Language', 'en');
    
            return [
                'id' => $plan->id,
                'name' => $locale === 'ar' ? $plan->name_ar : $plan->name_en,
                'academicList' => $locale === 'ar'
                    ? optional($plan->academic)->name_ar
                    : optional($plan->academic)->name_en,
                'academicLevel' => $plan->academicLevel,
                'expectedStudents' => $plan->expected_students,
            ];
        });
    
        return $this->ApiResponse($data, 'get study plans successfully', 200);
    }

    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        DB::transaction(function() use ($request, &$studyPlan) {
            $studyPlan = StudyPlane::create([
                'name_en'          => $request['nameEn'],
                'name_ar'          => $request['nameAr'],
                'academic_id' => $request['academicListId'],
                'academicLevel'   => $request['academicLevel'],
                'expected_students'=> $request['expectedStudents'],
            ]);

            foreach ($request['courseAssignments'] as $caData) {
                $courseAssignment = CourseAssignment::create([
                    'study_plan_id'     => $studyPlan->id,
                    'course_id'         => $caData['courseId'],
                    'lecture_groups'    => $caData['lectureGroups'],
                    'lab_groups'        => $caData['labGroups'] ?? 0,
                    'is_common'         => $caData['isCommon'],
                    'practical_in_labs' => $caData['practicalInLabs'],
                ]);

                if (!empty($caData['preferredLabs'])) {
                    $courseAssignment->preferredLabs()->attach($caData['preferredLabs']);
                }

                if (!empty($caData['lecturers'])) {
                    foreach ($caData['lecturers'] as $lectData) {
                        LecturerAssignment::create([
                            'course_assignment_id' => $courseAssignment->id,
                            'lecturer_id'    => $lectData['staffId'],
                            'num_groups'  => $lectData['numGroups'],
                            'type'        => 'lecturer',
                        ]);
                    }
                }

                if (!empty($caData['teachingAssistants'])) {
                    foreach ($caData['teachingAssistants'] as $taData) {
                        LecturerAssignment::create([
                            'course_assignment_id' => $courseAssignment->id,
                            'lecturer_id'    => $taData['staffId'],
                            'num_groups'  => $taData['numGroups'],
                            'type'        => 'teaching_assistant',
                        ]);
                    }
                }
            }
        });



        $studyPlan = StudyPlane::with([
           'academic', 
            'courseAssignments', 
            'courseAssignments.course' ,
            'courseAssignments.lecturerAssignments',
            'courseAssignments.preferredLabs',
            'courseAssignments.lecturerAssignments.lecturer',
            'courseAssignments.lecturerAssignments.lecturer.academicDegree'
        ])->find($studyPlan->id);

        return $this->ApiResponse(new StudyplanResource($studyPlan) , 'stored study plans successfully' , 200);

    
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
            'courseAssignments.lecturerAssignments.lecturer.academicDegree'
        ])->findOrFail($id);
    
        return $this->ApiResponse(new StudyplanResource($studyPlan), 'show study plan successfully', 200);
    }



    public function update(Request $request,  $id)
    {
        
    DB::transaction(function() use ($request, $id, &$studyPlan) {
        $studyPlan = StudyPlane::findOrFail($id);

        $studyPlan->update([
            'name_en'          => $request['nameEn'],
            'name_ar'          => $request['nameAr'],
            'academic_id'      => $request['academicListId'],
            'academicLevel'    => $request['academicLevel'],
            'expected_students'=> $request['expectedStudents'],
        ]);

        $studyPlan->courseAssignments()->delete();

        foreach ($request['courseAssignments'] as $caData) {
            $courseAssignment = CourseAssignment::create([
                'study_plan_id'     => $studyPlan->id,
                'course_id'         => $caData['courseId'],
                'lecture_groups'    => $caData['lectureGroups'],
                'lab_groups'        => $caData['labGroups'] ?? 0,
                'is_common'         => $caData['isCommon'],
                'practical_in_labs' => $caData['practicalInLabs'],
            ]);

            if (!empty($caData['preferredLabs'])) {
                $courseAssignment->preferredLabs()->attach($caData['preferredLabs']);
            }

            if (!empty($caData['lecturers'])) {
                foreach ($caData['lecturers'] as $lectData) {
                    LecturerAssignment::create([
                        'course_assignment_id' => $courseAssignment->id,
                        'lecturer_id'          => $lectData['staffId'],
                        'num_groups'           => $lectData['numGroups'],
                        'type'                 => 'lecturer',
                    ]);
                }
            }

            if (!empty($caData['teachingAssistants'])) {
                foreach ($caData['teachingAssistants'] as $taData) {
                    LecturerAssignment::create([
                        'course_assignment_id' => $courseAssignment->id,
                        'lecturer_id'          => $taData['staffId'],
                        'num_groups'           => $taData['numGroups'],
                        'type'                 => 'teaching_assistant',
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
        'courseAssignments.lecturerAssignments.lecturer.academicDegree'
    ])->find($id);

    return $this->ApiResponse(new StudyplanResource($studyPlan), 'updated study plan successfully', 200);
    }

    public function destroy( $id)
    {
        $studyPlan = StudyPlane::findOrFail($id);
        $studyPlan->delete(); 
        return $this->ApiResponse(null, 'deleted study plan successfully', 200);
    }
    
}
