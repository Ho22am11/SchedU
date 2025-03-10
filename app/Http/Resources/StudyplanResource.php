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
            'academic'         => $this->whenLoaded('academic', function () use ($request, $locale) {
                return $locale === 'ar' ? $this->academic->name_ar : $this->academic->name;
            }),
            'academicLevel'    => 'Level ' . $this->academicLevel,
            'expectedStudents' => $this->expected_students,
            'courseAssignments' => CourseAssignmentResource::collection($this->whenLoaded('courseAssignments')),

        ];
        
    }
}
