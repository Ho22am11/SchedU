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

    public function index(Request $request)
{
    $academics = Academic::with(['department', 'courses'])->get();

    $data = $academics->map(function($academic) use ($request) {
        $locale = $request->header('Accept-Language', 'en');

        return [
            'id' =>  $academic->id, 
            'name'  => $locale === 'ar' ? $academic->name_ar : $academic->name,
            'nameEn'  => $academic->name,
            'nameAr'  => $academic->name_ar,
            'department'  => $locale === 'ar' ? $academic->department->name_ar : $academic->department->name,
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
    
    
    
    
        return $this->ApiResponse(new AcademicResource($updatedAcademic), 'Updated academics successfully', 200);
    }

    
    public function destroy( $id)
    { 
        Academic::destroy($id);
        return $this->ApiResponse( null , 'delete academics successfully' , 200);

    }


}
