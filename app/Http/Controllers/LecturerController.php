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
        $lecturers = Lecturer::with(['department', 'timePreferences', 'academicDegree'])->get();
        return $this->ApiResponse( lecturerResource::collection($lecturers) , 'get lecturers successfully' , 200);

        
    }


   
    public function store(Request $request)
    {
        $createdStaff = [];

        DB::transaction(function() use ($request, &$createdStaff) {
            foreach ($request['staff'] as $staffData) {
                $staffMember = Lecturer::create([
                    'name'              => $staffData['name'],
                    'name_ar'           => $staffData['name_ar'] ?? null,
                    'department_id'     => $staffData['department_id'],
                    'academic_id'=> $staffData['academic_degree_id'],
                    'isPermanent'       => $staffData['isPermanent'],
                ]);

                foreach ($staffData['timingPreference'] as $tp) {
                    $staffMember->timePreferences()->create([
                        'day'         => $tp['day'],
                        'start_time'  => $tp['startTime'],
                        'end_time'    => $tp['endTime'],
                    ]);
                }
                $createdStaff[] = $staffMember->load(['department', 'timePreferences', 'academicDegree']);
            }
        });

        return $this->ApiResponse( lecturerResource::collection($createdStaff) , 'get lecturers successfully' , 200);
    }

    
    public function show( $id)
    {
        $lecturer = Lecturer::with(['department', 'timePreferences', 'academicDegree'])->find($id); 
    if (!$lecturer) {
        return $this->ApiResponse(null, 'Lecturer not found', 404);
    }
        return $this->ApiResponse(new lecturerResource($lecturer) , 'showed lecturer successfully' , 200);

    }


    public function update(Request $request , $id)
    {
        $updatedLecturer = [];
    
        DB::transaction(function() use ($request, $id) {
            // جلب سجل المُحاضر المطلوب للتحديث
            $lecturer = Lecturer::findOrFail($id);
    
            // تحديث الحقول الأساسية
            $lecturer->update([
                'name'           => $request['name'],
                'name_ar'        => $request['name_ar'] ?? null,
                'department_id'  => $request['department_id'],
                'academic_id'    => $request['academic_degree_id'],
                'isPermanent'    => $request['isPermanent'],
            ]);
    
            // حذف التفضيلات الزمنية القديمة
            $lecturer->timePreferences()->delete();
    
            // إنشاء التفضيلات الزمنية الجديدة
            foreach ($request['timingPreference'] as $tp) {
                $lecturer->timePreferences()->create([
                    'day'         => $tp['day'],
                    'start_time'  => $tp['startTime'],
                    'end_time'    => $tp['endTime'],
                ]);
            }
        });

        $updatedLecturer = Lecturer::with(['department', 'timePreferences', 'academicDegree'])->findOrFail($id);

    
        return $this->ApiResponse(new lecturerResource($updatedLecturer), 'lecturer updated successfully', 200);
    }
    
    
    public function destroy( $id)
    { 
        $lecturer = Lecturer::findOrFail($id);
        $lecturer->timePreferences()->delete();
        $lecturer->delete();
        return $this->ApiResponse( null , 'delete lecturer successfully' , 200);


    }

    public function getStaffByType(Request $request)
{
    $type = $request->query('type', ''); // يمكن أن تكون "lecturer" أو "teaching_assistant"
    
    $query = Lecturer::with(['department', 'academicDegree', 'timePreferences']);
    
    if ($type === 'lecturer') {
        // درجات المحاضرين
        $lecturerDegrees = ['professor', 'associate professor', 'assistant professor'];
        $query->whereHas('academicDegree', function($q) use ($lecturerDegrees) {
            $q->whereIn('name', $lecturerDegrees);
        });
    } elseif ($type === 'teaching_assistant') {
        // درجات معيدي التدريس
        $taDegrees = ['assistant lecturer', 'teaching assistant'];
        $query->whereHas('academicDegree', function($q) use ($taDegrees) {
            $q->whereIn('name', $taDegrees);
        });
    }
    
    $staff = $query->get();
    
    return response()->json($staff, 200);
}
}
