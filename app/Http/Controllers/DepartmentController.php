<?php

namespace App\Http\Controllers;

use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    use ApiResponseTrait ;
    public function index()
    {
        $departments = Department::all();

        $data = $departments->map(function($academic) {
            return [
                'id' => $academic->id,
                'nameEn'  => $academic->name,
                'nameAr'  => $academic->name_ar,
            ];
        });
        return $this->ApiResponse( $data , 'get departments successfully' , 200);
    }

   

   
    public function store(Request $request)
    {
        $department = Department::create($request->all());
        return $this->ApiResponse(new DepartmentResource($department) , 'stored department successfully' , 201);
    }

    
    public function show( $id)
    {
        $department = Department::find($id);
        return $this->ApiResponse(new DepartmentResource($department) , 'showed department successfully' , 200);

    }



    
    public function update(Request $request,  $id)
    {
        $department = Department::find($id);
        $department->update($request->all());
        return $this->ApiResponse(new DepartmentResource($department) , 'updated department successfully' , 200);

    }

    
    public function destroy( $id)
    { 
        Department::destroy($id);
        return $this->ApiResponse( null , 'delete department successfully' , 200);

    }
}
