<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EntryScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = $request->header('Accept-Language', 'en');

        return [
            'id' => $this->id,

            // Multiple courses support
            'courses' => CourseResource::collection($this->courses),

            'session_type' => $this->session_type,
            'group_info' => [
                'group_number' => $this->group_number,
                'total_groups' => $this->total_groups,
            ],
            'lab' => $this->room($this->lap, $this->lap_id, $this->lap_name),
            'hall' => $this->room($this->hall, $this->hall_id, $this->hall_name),
            'staff' => [
                'id' => $this->lecturer_id,
                'name' => $locale === 'ar'
                    ? ($this->lecturer_name_ar ?? $this->Lecturer?->name_ar)
                    : ($this->lecturer_name ?? $this->Lecturer?->name),
                'academic_degree' => $this->Lecturer?->academicDegree
                    ? new AcademicDegreeResource($this->Lecturer->academicDegree)
                    : null,

            ],
            'time_slot' => [
                'day' => $this->Day,
                'startTime' => \Str::substr($this->startTime, 0, 5),
                'endTime' => \Str::substr($this->endTime, 0, 5),
            ],
            // Multiple academic lists support
            'academic_lists' => $this->academics->map(function ($academic) use ($locale) {
                return [
                    'id' => $academic->id,
                    'name' => $locale === 'ar' ? ($academic->name_ar ?? $academic->name) : $academic->name,
                    'nameEn' => $academic->name ?? '',
                    'nameAr' => $academic->name_ar ?? '',
                ];
            }),

            'student_count' => $this->student_count,

            // Multiple academic levels support
            'academic_levels' => $this->academic_levels ?? [],

            // Multiple departments support using accessor
            'departments' => DepartmentResource::collection($this->departments),

        ];
    }

    /**
     * Room display data with the point-in-time snapshot name taking
     * precedence. Legacy entries (no snapshot) fall back to the live
     * relation; entries whose room was deleted keep their recorded name.
     */
    private function room($live, $id, ?string $snapshotName): ?array
    {
        if ($live) {
            return $snapshotName !== null
                ? array_merge($live->toArray(), ['name' => $snapshotName])
                : $live->toArray();
        }

        return ($id !== null && $snapshotName !== null)
            ? ['id' => $id, 'name' => $snapshotName]
            : null;
    }
}
