<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    use ApiResponseTrait ;
    public function index()
    {
        $course = Course::all();
        return $this->ApiResponse($course , 'get course successfully' , 200);
    }

   

   
    public function store(Request $request)
    {
        $course = Course::create($request->all());
        return $this->ApiResponse($course , 'stored course successfully' , 201);
    }

    
    public function show( $id)
    {
        $spaces = Course::find($id);
        return $this->ApiResponse($spaces , 'showed course successfully' , 200);

    }



    
    public function update(Request $request,  $id)
    {
        $course = Course::find($id);
        $course->update($request->all());
        return $this->ApiResponse($course , 'updated course successfully' , 200);

    }

    
    public function destroy( $id)
    { 
        Course::destroy($id);
        return $this->ApiResponse( null , 'delete course successfully' , 200);

    }
}
