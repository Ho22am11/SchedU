<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademicResource extends JsonResource
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
            'name' => $locale === 'ar' ? $this->name_ar : $this->name,
            'department' => $locale === 'ar' ?  $this->department->name_ar : $this->name,
            'courses' => $this->courses ,

        ];
    }
}
