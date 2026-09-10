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
            'id' => $this->id,
            'name' => $locale === 'ar' ? $this->nameAr : $this->nameEn,
            'nameAr' => $this->nameAr,
            'nameEn' => $this->nameEn,
            'create_at' => $this->created_at,
            'updated_at' => $this->updated_at?->toISOString(),
            'reserved_period' => $this->reservedPeriod(),
            'entries' => EntryScheduleResource::collection($this->whenLoaded('entries')),
            'metadata' => $this->whenLoaded('entries', function () use ($entries) {
                if (! $entries) {
                    return [];
                }

                // Calculate unique courses from course_ids arrays; external
                // courses count as subjects too (one per external course).
                $allCourseIds = collect();
                $externalCourseIds = collect();
                foreach ($entries as $entry) {
                    if ($entry->course_ids && is_array($entry->course_ids)) {
                        $allCourseIds = $allCourseIds->merge($entry->course_ids);
                    }
                    if ($entry->external_course_id) {
                        $externalCourseIds->push($entry->external_course_id);
                    }
                }

                return [
                    'total_sessions' => $entries->count(),
                    'total_courses' => $allCourseIds->unique()->count()
                        + $externalCourseIds->unique()->count(),
                    'total_rooms' => $entries
                        ->pluck('hall_id')
                        ->merge($entries->pluck('lap_id'))
                        ->filter()
                        ->unique()
                        ->count(),
                    // Staffless external entries have no lecturer to count.
                    'total_staff' => $entries->pluck('lecturer_id')->filter()->unique()->count(),
                ];
            }),
        ];
    }
}
