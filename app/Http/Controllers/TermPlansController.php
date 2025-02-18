<?php

namespace App\Http\Controllers;

use App\Http\Requests\TermItemRequest;
use App\Http\Resources\TermItemResource;
use App\Http\Resources\TermResource;
use App\Models\Term_item;
use App\Models\Term_plan;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class TermPlansController extends Controller
{
    use ApiResponseTrait ;
    public function index()
    {
        $data = Term_plan::all();
        return $this->ApiResponse($data , 'get term plan successfully' , 200);
    }


   
    public function store(Request $request)
    {
        $data = Term_plan::create($request->all());
        return $this->ApiResponse($data , 'stored term plan successfully' , 201);
    }

    
    public function show( $id)
    {
        $data = Term_plan::with('termItem')->find($id);
        return $this->ApiResponse( new TermResource($data) , 'showed term plan successfully' , 200);

    }


    public function update(Request $request, $id)
    {
        $data = Term_plan::find($id);
        
        if (!$data) {
            return $this->ApiResponse(null, 'term plan not found', 404);
        }

        $data->update($request->all());
        return $this->ApiResponse($data, 'Updated term plan successfully', 200);
    }

    
    public function destroy( $id)
    { 
        Term_plan::destroy($id);
        return $this->ApiResponse( null , 'delete term plan successfully' , 200);

    }

    public function AddItem(TermItemRequest $request)
    {
        $data = Term_item::create($request->all());
        return $this->ApiResponse(new TermItemResource($data) , 'stored term item successfully' , 201);
    }

    public function ShowItem($id)
    {
        $data = Term_item::find($id);
        return $this->ApiResponse(new TermItemResource($data ), 'stored term item successfully' , 201);
    }
    public function RemoveItem( $id)
    { 
        Term_item::destroy($id);
        return $this->ApiResponse( null , 'delete term item successfully' , 200);

    }


    
}
