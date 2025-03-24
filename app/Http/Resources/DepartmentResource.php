<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
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
            'id'   => $this->id,
            'name' => $locale === 'ar' ? $this->name_ar : $this->name,
            'nameEn' => $this->name , 
            'nameAr' => $this->name_ar , 
        ];
    }
}
