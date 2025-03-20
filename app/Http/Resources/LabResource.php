<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LabResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
       $data = parent::toArray($request);

       return array_merge($data , [
        'name' => $this->name ,
        'capacity' => $this->capacity ,
        'labType' => $this->labType ,
        'usedInNonSpecialistCourses' => $this->usedInNonSpecialistCourses ,
        'availability' => $this->availability ,
    ]);

    }

    public function MaplapType($value){
        $locale = request()->header('Accept-Language', 'en');
        $en_value = [1 => "general" , 0 => "specialist"]; 
        $ar_value = [1 => "عام" , 0 => "متخصص"]; 
        return $locale === 'ar' ? $ar_value[$value] : $en_value[$value];

    }
}
