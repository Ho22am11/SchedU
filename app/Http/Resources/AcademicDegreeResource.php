<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademicDegreeResource extends JsonResource
{
    
    public function toArray(Request $request): array
    {
        $locale = $request->header('Accept-Language', 'en');
        return [
            'id'     => $this->id,
            'name'   => $locale === 'ar' ? $this->name_ar : $this->name,
            'prefix' => $locale === 'ar' ? $this->prefix_ar : $this->prefix,
        ];
    }

   
}
