<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    use ApiResponseTrait ;
    public function index()
    {
        $department = Department::all();
        return $this->ApiResponse($department , 'get departments successfully' , 200);
    }

   

   
    public function store(Request $request)
    {
        $department = Department::create($request->all());
        return $this->ApiResponse($department , 'stored department successfully' , 201);
    }

    
    public function show( $id)
    {
        $spaces = Department::find($id);
        return $this->ApiResponse($spaces , 'showed department successfully' , 200);

    }



    
    public function update(Request $request,  $id)
    {
        $departments = Department::find($id);
        $departments->update($request->all());
        return $this->ApiResponse($departments , 'updated department successfully' , 200);

    }

    
    public function destroy( $id)
    { 
        Department::destroy($id);
        return $this->ApiResponse( null , 'delete department successfully' , 200);

    }
}
