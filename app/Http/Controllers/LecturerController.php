<?php

namespace App\Http\Controllers;

use App\Http\Resources\lecturerResource;
use App\Models\Lecturer;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class LecturerController extends Controller
{
    use ApiResponseTrait ;
    public function index()
    {
        $lecturers = Lecturer::all();
        return $this->ApiResponse( lecturerResource::collection($lecturers) , 'get lecturers successfully' , 200);
    }


   
    public function store(Request $request)
    {
        $lecturer = Lecturer::create($request->all());
        return $this->ApiResponse(new lecturerResource($lecturer) , 'stored lecturer successfully' , 201);
    }

    
    public function show( $id)
    {
        $lecturer = Lecturer::with('termItem')->find($id); 
    if (!$lecturer) {
        return $this->ApiResponse(null, 'Lecturer not found', 404);
    }
        return $this->ApiResponse(new lecturerResource($lecturer) , 'showed lecturer successfully' , 200);

    }


    public function update(Request $request, $id)
    {
        $lecturer = Lecturer::find($id);
        
        if (!$lecturer) {
            return $this->ApiResponse(null, 'lecturer not found', 404);
        }

        $lecturer->update($request->all());
        return $this->ApiResponse(new lecturerResource($lecturer), 'Updated lecturer successfully', 200);
    }

    
    public function destroy( $id)
    { 
        Lecturer::destroy($id);
        return $this->ApiResponse( null , 'delete lecturer successfully' , 200);

    }
}
