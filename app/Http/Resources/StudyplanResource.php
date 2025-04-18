<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudyplanResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        $locale = $request->header('Accept-Language', 'en');

        return [
            'id'               => $this->id,
            'name'             => $locale === 'ar' ? $this->name_ar : $this->name_en,
            'nameEn' => $this->name_en , 
            'nameAr' => $this->name_ar , 
            'academicList' => $this->whenLoaded('academic', function () use ($request, $locale) {
                return [
                    'id' => $this->academic->id,
                    'name' => $locale === 'ar' ? $this->academic->name_ar : $this->academic->name,
                    'nameEn' =>  $this->academic->name , 
                    'nameAr' => $this->academic->name_ar , 
                ];
            }),
            'academicLevel'    => 'Level ' . $this->academicLevel,
            'expectedStudents' => $this->expected_students,
            'courseAssignments' => CourseAssignmentResource::collection($this->whenLoaded('courseAssignments')),

        ];
        
    }
}
