<?php

namespace App\Http\Controllers;

use App\Http\Resources\lecturerResource;
use App\Models\Lecturer;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LecturerController extends Controller
{
    use ApiResponseTrait ;
    public function index()
    {
        $lecturers = Lecturer::with(['department', 'timingPreference', 'academicDegree'])->get();
        $data = $lecturers->map(function ($lecturer) {
            return [
                'id' => $lecturer->id,
                'nameEn' => $lecturer->name,
                'nameAr' => $lecturer->name_ar,
                'department' => [
                    'id' => optional($lecturer->department)->id,
                    'name' => optional($lecturer->department)->name,
                ],
                'academic_degree' => [
                    'id' => optional($lecturer->academicDegree)->id,
                    'name' => optional($lecturer->academicDegree)->name,
                    'prefix' => optional($lecturer->academicDegree)->prefix,
                ],
                'isPermanent' => $lecturer->isPermanent,
                'timingPreference' => $lecturer->timingPreference->map(function ($time) {
                    return [
                        'id' => $time->id,
                        'day' => $time->day,
                        'startTime' => $time->startTime,
                    'endTime' =>$time->endTime,
                    ];
                }),
            ];
        });
    
        return $this->ApiResponse($data, 'get lecturers successfully', 200);

        
    }


   
    public function store(Request $request)
    {
        $createdStaff = [];

        DB::transaction(function() use ($request, &$createdStaff) {

            foreach ($request['staff'] as $staffData) {
                $staffMember = Lecturer::create([
                    'name'              => $staffData['nameEn'],
                    'name_ar'           => $staffData['nameAr'] ?? null,
                    'department_id'     => $staffData['department_id'],
                    'academic_id'=> $staffData['academic_degree_id'],
                    'isPermanent'       => $staffData['isPermanent'],
                ]);

                foreach ($staffData['timingPreference'] as $tp) {
                    $staffMember->timingPreference()->create([
                        'day'         => $tp['day'],
                        'startTime' => $tp['startTime'],
                        'endTime' => $tp['endTime'],
                    ]);
                }
                $createdStaff[] = $staffMember->load(['department', 'timingPreference', 'academicDegree']);
            }
        });

        return $this->ApiResponse( lecturerResource::collection($createdStaff) , 'get lecturers successfully' , 200);
    }

    
    public function show( $id)
    {
        $lecturer = Lecturer::with(['department', 'timingPreference', 'academicDegree'])->find($id); 
    if (!$lecturer) {
        return $this->ApiResponse(null, 'Lecturer not found', 404);
    }
        return $this->ApiResponse(new lecturerResource($lecturer) , 'showed lecturer successfully' , 200);

    }


    public function update(Request $request , $id)
    {
        $updatedLecturer = [];
    
        DB::transaction(function() use ($request, $id) {
            $lecturer = Lecturer::findOrFail($id);
    
            $lecturer->update([
                'name'           => $request['nameEn'],
                'name_ar'        => $request['nameAr'] ?? null,
                'department_id'  => $request['department_id'],
                'academic_id'    => $request['academic_degree_id'],
                'isPermanent'    => $request['isPermanent'],
            ]);
    
            $lecturer->timingPreference()->delete();
    
            foreach ($request['timingPreference'] as $tp) {
                $lecturer->timingPreference()->create([
                    'day'         => $tp['day'],
                    'startTime' => $tp['startTime'],
                    'endTime' => $tp['endTime'],
                ]);
            }
        });

        $updatedLecturer = Lecturer::with(['department', 'timingPreference', 'academicDegree'])->findOrFail($id);

    
        return $this->ApiResponse(new lecturerResource($updatedLecturer), 'lecturer updated successfully', 200);
    }
    
    
    public function destroy( $id)
    { 
        $lecturer = Lecturer::findOrFail($id);
        $lecturer->timingPreference()->delete();
        $lecturer->delete();
        return $this->ApiResponse( null , 'delete lecturer successfully' , 200);


    }

    public function getStaffByType(Request $request)
{
    $type = $request->query('type', ''); 
    
    $query = Lecturer::with(['department', 'academicDegree', 'timingPreference']);
    
    if ($type === 'lecturer') {
        $lecturerDegrees = ['professor', 'associate professor', 'assistant professor'];
        $query->whereHas('academicDegree', function($q) use ($lecturerDegrees) {
            $q->whereIn('name', $lecturerDegrees);
        });
    } elseif ($type === 'teaching_assistant') {
        $taDegrees = ['assistant lecturer', 'teaching assistant'];
        $query->whereHas('academicDegree', function($q) use ($taDegrees) {
            $q->whereIn('name', $taDegrees);
        });
    }
    
    $staff = $query->get();
    
    return $this->ApiResponse(  lecturerResource::collection($staff), 'get lecturer successfully' , 200);

}
}
