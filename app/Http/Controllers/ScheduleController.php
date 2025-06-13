<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScheduleRequest;
use App\Http\Resources\ScheduleResource;
use App\Models\Schedule;
use App\Models\ScheduleEntry;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    use ApiResponseTrait ;
    public function index()
    {
        $schedules = Schedule::latest()->get();

        return $this->ApiResponse($schedules , 'schedule stored successffly' , 201);

    
    }


    public function store(StoreScheduleRequest $request)
{
    $schedule = Schedule::create([
        'nameEn' => $request->nameEn,
        'nameAr' => $request->nameAr,
    ]);

    foreach ($request->schedule as $entry) {
        $schedule->entries()->create([
            'course_id'     => $entry['course_id'],
            'session_type'  => $entry['session_type'],
            'group_number'  => $entry['group_info']['group_number'],
            'total_groups'  => $entry['group_info']['total_groups'],
            'hall_id'       => $entry['hall_id'],
            'lap_id'        => $entry['lab_id'],             // لاحظ: JSON فيه lab_id، والعمود اسمه lap_id
            'lecturer_id'   => $entry['lecturer_id'],
            'Day'           => $entry['time_slot']['day'],
            'startTime'     => $entry['time_slot']['start_time'],
            'endTime'       => $entry['time_slot']['end_time'],
            'student_count' => $entry['student_count'],
            'academic_id'   => $entry['academic_id'],
            'academic_level'=> $entry['academic_level'],
            'department_id' => $entry['department_id'],
        ]);
    }

    $schedule = Schedule::with(['entries.lecturer.academicDegree'])
                        ->findOrFail($schedule->id);

    return $this->ApiResponse(
        new ScheduleResource($schedule->load('entries')),
        'Schedule stored successfully',
        201
    );
}
        


   public function show(Request $request, $id)
{
    $schedule = Schedule::with(['entries.course', 'entries.lap', 'entries.hall', 'entries.lecturer.academicDegree'])
        ->findOrFail($id);

    $staffId = $request->query('staff_id');
    $hallId = $request->query('hall_id');
    $labId = $request->query('lab_id');
    $academicListId = $request->query('academic_list_id');
    $academicLevel = $request->query('academic_level');
    $departmentId = $request->query('department_id');

    $filteredEntries = $schedule->entries->filter(function ($entry) use (
        $staffId, $hallId, $labId, $academicListId, $academicLevel, $departmentId
    ) {
        return
            (!$staffId || $entry->lecturer_id == $staffId) &&
            (!$hallId || $entry->hall_id == $hallId) &&
            (!$labId || $entry->lap_id == $labId) &&
            (!$academicListId || $entry->academic_id == $academicListId) &&
            (!$academicLevel || $entry->academic_level == $academicLevel) &&
            (!$departmentId || ($entry->lecturer && $entry->lecturer->department_id == $departmentId));
    });

    $schedule->setRelation('entries', $filteredEntries->values());

    return new ScheduleResource($schedule);
}

   public function update(StoreScheduleRequest $request, $id)
{
    $validated = $request->validated();

    $schedule = Schedule::findOrFail($id);

    $schedule->update([
        'nameEn' => $validated['nameEn'],
        'nameAr' => $validated['nameAr'],
    ]);

    $schedule->entries()->delete();

    foreach ($validated['schedule'] as $entry) {
        $schedule->entries()->create([
            'course_id'     => $entry['course_id'],
            'session_type'  => $entry['session_type'],
            'group_number'  => $entry['group_info']['group_number'],
            'total_groups'  => $entry['group_info']['total_groups'],
            'hall_id'       => $entry['hall_id']        ?? null,
            'lap_id'        => $entry['lab_id']         ?? null,  // لاحظ اسم الحقل map مع lap_id
            'lecturer_id'   => $entry['lecturer_id'],
            'Day'           => $entry['time_slot']['day'],
            'startTime'     => $entry['time_slot']['start_time'],
            'endTime'       => $entry['time_slot']['end_time'],
            'student_count' => $entry['student_count'],
            'academic_id'   => $entry['academic_id'],
            'academic_level'=> $entry['academic_level'],
            'department_id' => $entry['department_id'],
        ]);
    }

    $schedule->load(['entries.lecturer.academicDegree']);

    return $this->ApiResponse(
        new ScheduleResource($schedule),
        'Schedule updated successfully',
        200
    );
}
    
    
    
    public function destroy(string $id)
    {
        $schedule = Schedule::findOrFail($id);
         $schedule->entries()->delete();
         Schedule::destroy($id);

         return $this->ApiResponse(
            null,
            'Schedule deleted successfully',
            200
        );

    }
}
