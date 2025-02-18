<?php

namespace App\Http\Controllers;

use App\Http\Resources\AcadmicSpaceResource;
use App\Models\Acadmic_space;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class AcadmicSpaceController extends Controller
{
    use ApiResponseTrait ;
    public function index()
    {
        $spaces = Acadmic_space::all();
        return $this->ApiResponse( AcadmicSpaceResource::collection($spaces) , 'get acadmic space successfully' , 200);
    }

 

   
    public function store(Request $request)
    {
        $spaces = Acadmic_space::create($request->all());
        return $this->ApiResponse(new AcadmicSpaceResource($spaces) , 'stored acadmic space successfully' , 201);
    }

    
    public function show( $id)
    {
        $spaces = Acadmic_space::with('termItem')->find($id);
        return $this->ApiResponse(new AcadmicSpaceResource($spaces) , 'showed acadmic space successfully' , 200);

    }


    public function update(Request $request,  $id)
    {
        $spaces = Acadmic_space::find($id);
        
        if (!$spaces) {
            return $this->ApiResponse(null, 'Academic space not found', 404);
        }

        $spaces->update($request->all());
        return $this->ApiResponse(new AcadmicSpaceResource($spaces), 'Updated academic space successfully', 200);
    }

    
    public function destroy( $id)
    { 
        Acadmic_space::destroy($id);
        return $this->ApiResponse( null , 'delete acadmic space successfully' , 200);

    }
}
