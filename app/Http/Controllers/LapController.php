<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\LabResource;
use App\Models\Lap;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LapController extends Controller
{
  
    use ApiResponseTrait ;

    public function index()
    {
        $labs = Lap::with('timePreferences')->get();
        return $this->ApiResponse( LabResource::collection($labs), 'get labs successfully', 200);
    }


    public function store(Request $request)
    {
        $lepsCreated = [];
    
        DB::transaction(function () use ($request, &$lepsCreated) {
            foreach ($request['labs'] as $labData) {
                $lap = Lap::create([
                    'name' => $labData['name'],
                    'capacity' => $labData['capacity'],
                    'labType' => $labData['labType'],
                    'usedInNonSpecialistCourses' => $labData['usedInNonSpecialistCourses'],
                ]);
    
                foreach ($labData['availability'] as $timePref) {
                    $lap->timePreferences()->create([
                        'day' => $timePref['day'],
                        'start_time' => $timePref['startTime'],
                        'end_time' => $timePref['endTime'],
                    ]);
                }
    
                $lepsCreated[] = $lap->load('timePreferences');
            }
        });
    
        return $this->ApiResponse(LabResource::collection($lepsCreated), 'created labs successfully', 201);
    }
    

    public function show($id)
    {
        $lap = Lap::with('timePreferences')->find($id);
        return $this->ApiResponse(new LabResource($lap), 'created labs successfully', 201);

    }


    public function update(Request $request, $id)
    {
        DB::transaction(function () use ($request, $id) {
            $lap = Lap::findOrFail($id);
    
            $lap->update([
                'name'     => $request['name'],
                'capacity' => $request['capacity'],
                'labType' => $request['labType'],
                'usedInNonSpecialistCourses' => $request['usedInNonSpecialistCourses'],
            ]);
    
            $lap->timePreferences()->delete();
    
            foreach ($request['availability'] as $timePref) {
                $lap->timePreferences()->create([
                    'day'        => $timePref['day'],
                    'start_time' => $timePref['startTime'],
                    'end_time'   => $timePref['endTime'],
                ]);
            }
        });
    
        $lap = Lap::with('timePreferences')->find($id);
    
        return $this->ApiResponse(new LabResource($lap), 'updated lab  successfully', 200);
    }

    public function destroy(string $id)
    {
        $lap = Lap::findOrFail($id);
        $lap->timePreferences()->delete();
        $lap->delete();
        return $this->ApiResponse(null , 'deleted lab  successfully', 200);    }
}
