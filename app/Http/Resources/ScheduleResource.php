<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = $request->header('Accept-Language', 'en');
        $entries = $this->whenLoaded('entries');


        return [
            'id' => $this->id ,
            'name' => $locale === 'ar' ? $this->nameAr : $this->nameEn,
            'nameAr' => $this->nameAr,
            'nameEn' => $this->nameEn,
            'entries' => EntryScheduleResource::collection($this->whenLoaded('entries')),
            'metadata' => $this->whenLoaded('entries', function () use ($entries) {
            return [
                'total_sessions' => $entries->count(),
                'total_courses' => $entries->pluck('course_id')->unique()->count(),
                'total_rooms' => $entries
                    ->pluck('hall_id')
                    ->merge($entries->pluck('lap_id'))
                    ->filter()
                    ->unique()
                    ->count(),
                'total_staff' => $entries->pluck('lecturer_id')->unique()->count(),
            ];
            }),
        ];
    }
}
