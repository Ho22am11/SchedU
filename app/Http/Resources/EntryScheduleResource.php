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
            // Multiple courses support
            'courses' => CourseResource::collection($this->courses),


            'session_type' => $this->session_type,
            'group_info' => [
                'group_number' => $this->group_number,
                'total_groups' => $this->total_groups,
            ],
            'lab' => $this->lap ? $this->lap : null,
            'hall' => $this->hall ? $this->hall : null,
            'staff' => [
                'id' => $this->Lecturer->id,
                'name' => $locale === 'ar' ? $this->Lecturer->name_ar : $this->Lecturer->name,
                'academic_degree' => new AcademicDegreeResource($this->Lecturer->academicDegree),

            ],
            'time_slot' => [
                'day' => $this->Day,
                'startTime' => \Str::substr($this->startTime, 0, 5),
                'endTime' => \Str::substr($this->endTime, 0, 5),
            ],
            // Multiple academic lists support
            'academic_lists' => $this->academics->map(function ($academic) use ($locale) {
                return [
                    'id' => $academic->id,
                    'name' => $locale === 'ar' ? ($academic->name_ar ?? $academic->name) : $academic->name,
                    'nameEn' => $academic->name ?? '',
                    'nameAr' => $academic->name_ar ?? '',
                ];
            }),

            'student_count' => $this->student_count,

            // Multiple academic levels support
            'academic_levels' => $this->academic_levels ?? [],

            // Multiple departments support using accessor
            'departments' => DepartmentResource::collection($this->departments),

        ];
    }
}
