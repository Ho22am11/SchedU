<?php

namespace App\Http\Controllers;

use App\Http\Resources\AcademicResource;
use App\Models\Academic;
use App\Models\Academic_item;
use App\Models\Course;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;


class AcademicController extends Controller
{
    use ApiResponseTrait ;

    public function index()
    {

        $academics = Academic::with(['department', 'courses'])->get();

    $data = $academics->map(function($academic) {
        return [
            'name'            => $academic->name,
            'name_ar'            => $academic->name_ar,
            'department'      => optional($academic->department)->name,
            'department_ar'      => optional($academic->department)->name_ar,
            'number_of_courses' => $academic->courses->count(),
        ];
    });

    return $this->ApiResponse($data, 'get academics successfully', 200);
    }


   
    public function store(Request $request)
    {
        DB::transaction(function() use ($request, &$academic) {
            $academic = Academic::create([
                'name'         => $request['nameEn'],
                'name_ar'      => $request['nameAr'],
                'department_id'=> $request['departmentId'],
            ]);

            foreach ($request['courses'] as $courseData) {
                Course::create([
                    'code'               => $courseData['code'],
                    'name_en'            => $courseData['nameEn'],
                    'name_ar'            => $courseData['nameAr'],
                    'lecture_hours'      => $courseData['lectureHours'],
                    'practical_hours'    => $courseData['practicalHours'],
                    'credit_hours'       => $courseData['creditHours'],
                    'practical_components' => $courseData['preRequisiteCourseCode'] ?? '',
                    'academic_id'        => $academic->id,
                ]);
            }
        });

        $academicWithCourses = Academic::find($academic->id);

        return $this->ApiResponse(new AcademicResource($academicWithCourses) , 'get academics successfully' , 200);

    
    }

    
    public function show( $id)
    {
        $data = Academic::with('courses')->find($id);

        return $this->ApiResponse(new AcademicResource($data) , 'showed academics successfully' , 200);

    }


    public function update(Request $request, $id)

{


    DB::transaction(function () use ($request, $id, &$academic) {
        $academic = Academic::findOrFail($id);

        $academic->update([
            'name'         => $request['nameEn'],
            'name_ar'      => $request['nameAr'],
            'department_id'=> $request['departmentId'],
        ]);

        $academic->courses()->delete();
        foreach ($request['courses'] as $courseData) {
            Course::create([
                'code'                => $courseData['code'],
                'name_en'             => $courseData['nameEn'],
                'name_ar'             => $courseData['nameAr'],
                'lecture_hours'       => $courseData['lectureHours'],
                'practical_hours'     => $courseData['practicalHours'],
                'credit_hours'        => $courseData['creditHours'],
                'practical_components'=> $courseData['preRequisiteCourseCode'] ?? '',
                'academic_id'         => $academic->id,
            ]);
        }
    });
    
    $updatedAcademic = Academic::with('courses')->findOrFail($id);
    
    
    
    
        return $this->ApiResponse($updatedAcademic, 'Updated academics successfully', 200);
    }

    
    public function destroy( $id)
    { 
        Academic::destroy($id);
        return $this->ApiResponse( null , 'delete academics successfully' , 200);

    }


}
