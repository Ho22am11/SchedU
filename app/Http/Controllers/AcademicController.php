<?php

namespace App\Http\Controllers;

use App\Models\Academic;
use App\Models\Academic_item;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class AcademicController extends Controller
{
    use ApiResponseTrait ;

    public function index()
    {
        
        $data = Academic::all();
        return $this->ApiResponse($data , 'get academics successfully' , 200);
    }


   
    public function store(Request $request)
    {
        $data = Academic::create($request->all());
        return $this->ApiResponse($data , 'stored academics successfully' , 201);
    }

    
    public function show( $id)
    {
        $data = Academic::with('courses')->find($id);

        return $this->ApiResponse($data , 'showed academics successfully' , 200);

    }


    public function update(Request $request, $id)
    {
        $data = Academic::find($id);

        
        if (!$data) {
            return $this->ApiResponse(null, 'academics not found', 404);
        }

        $data->update($request->all());
        return $this->ApiResponse($data, 'Updated academics successfully', 200);
    }

    
    public function destroy( $id)
    { 
        Academic::destroy($id);
        return $this->ApiResponse( null , 'delete academics successfully' , 200);

    }


    public function AddCourse(Request $request){
        $cousrs = Academic_item::create($request->all());
        return $this->ApiResponse( null , 'add cousrs to academics successfully' , 201);

    }

    public function RemoveCourse($id){
        Academic_item::destroy($id);
        return $this->ApiResponse( null , 'delete remove course successfully' , 200);

    }
}
