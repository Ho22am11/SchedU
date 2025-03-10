<?php

namespace App\Http\Controllers;

use App\Models\AcademicDegree;
use App\Http\Controllers\Controller;
use App\Http\Resources\AcademicDegreeResource;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class AcademicDegreeController extends Controller
{
    use ApiResponseTrait;

    public function index()
    {
        $AcademicDegree = AcademicDegree::all();
        return $this->ApiResponse( AcademicDegreeResource::collection($AcademicDegree) , 'get acadmic Degree successfully' , 200);

    }

   
}
