<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EntryScheduleResource extends JsonResource
{
   
    public function toArray(Request $request): array
    {
        $locale = $request->header('Accept-Language', 'en');
        return [
            'course' => new CourseResource($this->course) ,
            'session_type' => $this->session_type ,
            'group_info' => [
                'group_number' => $this->group_number,
                'total_groups' => $this->total_groups,
            ],
            'lab' => $this->lap ? $this->lap : null  ,
            'hall' => $this->hall ? $this->hall : null  ,
            'staff' => [
                'name'=> $locale === 'ar' ? $this->Lecturer->name_ar : $this->Lecturer->name,
                'academic_degree' => new AcademicDegreeResource($this->Lecturer->academicDegree),

            ],
            'time_slot ' => [
                'Day' => $this->Day,
                 'start_time' => \Str::substr($this->startTime, 0, 5),
                  'end_time'   => \Str::substr($this->endTime,   0, 5),
            ],
            'student_count' => $this->student_count,
            'academic_id' => $locale === 'ar' ? $this->academic->name_ar : $this->academic->name ,
            'academic_level' => $this->academic_level ,
            'department' => $this->department ,
            

        ];
    }
}
