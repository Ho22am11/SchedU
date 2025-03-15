<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Hall;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HallController extends Controller
{
    use ApiResponseTrait ;
    public function index()
    {
        $halls = Hall::all();
        $halls->load('timePreferences');
        return $this->ApiResponse($halls , 'get halls successfully' , 200);

    }




    public function store(Request $request)
    {
        

        $hallsCreated = [];

        DB::transaction(function () use ($request, &$hallsCreated) {
            foreach ($request['halls'] as $hallData) {
                $hall = Hall::create([
                    'name' => $hallData['name'],
                    'capacity' => $hallData['capacity'],
                ]);

                foreach ($hallData['availability'] as $timePref) {
                    $hall->timePreferences()->create([
                        'day' => $timePref['day'],
                        'start_time' => $timePref['startTime'],
                        'end_time' => $timePref['endTime'],
                    ]);
                }

                $hallsCreated[] = $hall->load('timePreferences');
            }
        });


        return $this->ApiResponse($hallsCreated , 'created halls successfully' , 201);


    }
    


    public function show( $id)
    {
        $hall = Hall::with('timePreferences')->find($id);
        return $this->ApiResponse($hall , 'get hall successfully' , 200);

    }

    public function update(Request $request, $id)
    {
       
    
        DB::transaction(function () use ($request, $id) {
            $hall = Hall::findOrFail($id);
    
            $hall->update([
                'name'     => $request['name'],
                'capacity' => $request['capacity'],
            ]);
    
            $hall->timePreferences()->delete();
    
            foreach ($request['availability'] as $timePref) {
                $hall->timePreferences()->create([
                    'day'        => $timePref['day'],
                    'start_time' => $timePref['startTime'],
                    'end_time'   => $timePref['endTime'],
                ]);
            }
        });
    
        $hall = Hall::with('timePreferences')->find($id);
    
        return $this->ApiResponse($hall, 'updated hall  successfully', 200);
    }
    

 
    public function destroy( $id)
    {
        $hall = Hall::findOrFail($id);
        $hall->timePreferences()->delete();
        $hall->delete();
        return $this->ApiResponse(null , 'deleted hall  successfully', 200);

    }
}
