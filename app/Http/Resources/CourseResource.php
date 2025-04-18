<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = $request->header('Accept-Language', 'en');

        return [
            'name' => $locale === 'ar' ? $this->name_ar : $this->name_en,
            'id'                   => $this->id,
            'code'                 => $this->code,
            'nameAr'              => $this->name_ar,
            'nameEn'              => $this->name_en,
            'lectureHours'        => $this->lecture_hours,
            'practicalHours'      => $this->practical_hours,
            'creditHours'         => $this->credit_hours,
            'academic_id'          => $this->academic_id,
        ];
    }
}
