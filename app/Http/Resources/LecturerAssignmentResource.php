<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LecturerAssignmentResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        $locale = $request->header('Accept-Language', 'en');
        return [
            'id' => $this->lecturer_id ,
            'name' => $this->when($this->relationLoaded('lecturer'), function() use ($locale) {
                return $locale === 'ar' ? $this->lecturer->name_ar : $this->lecturer->name;
            }, 'Lecturer not loaded'),
            'nameEn' =>  $this->lecturer->name ,
            'nameAr' => $this->lecturer->name_ar,

            'academic_degree' => $this->when(
                $this->relationLoaded('lecturer') && $this->lecturer->relationLoaded('academicDegree'),
                function() use ($locale) {
                    return new AcademicDegreeResource($this->lecturer->academicDegree);
                },
                'Academic degree not loaded'
            ),
            'num_groups'    => $this->num_groups,        

        ];
    }
}
