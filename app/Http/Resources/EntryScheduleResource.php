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

            // Source discriminator: local course sessions render exactly as
            // before; external entries carry external_course + nullable
            // staff/room and empty local arrays.
            'entry_kind' => $this->entry_kind ?? 'course',

            // Multiple courses support (empty for external entries)
            'courses' => CourseResource::collection($this->courses),

            'external_course' => $this->when(
                ($this->entry_kind ?? 'course') === 'external',
                fn () => $this->externalCourseSummary($locale)
            ),

            'session_type' => $this->session_type,
            'group_info' => [
                'group_number' => $this->group_number,
                'total_groups' => $this->total_groups,
            ],
            'lab' => $this->room($this->lap, $this->lap_id, $this->lap_name),
            'hall' => $this->room($this->hall, $this->hall_id, $this->hall_name),
            // Staffless entries (external labs without internal staff) and
            // external-lecturer lectures have no staff at all.
            'staff' => $this->lecturer_id === null ? null : [
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
            // Multiple academic lists support (empty for external entries)
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
     * The external course behind the entry: display labels in both locales
     * plus the venue so the UI can render "External venue" for lectures the
     * outside faculty hosts. The restricting FK keeps the row alive as long
     * as schedule history refers to it.
     */
    private function externalCourseSummary(string $locale): ?array
    {
        $course = $this->externalCourse;

        if ($course === null) {
            return null;
        }

        return [
            'id' => $course->id,
            'code' => $course->code,
            'name' => $locale === 'ar' ? $course->name_ar : $course->name_en,
            'nameEn' => $course->name_en,
            'nameAr' => $course->name_ar,
            'requesting_entity' => $locale === 'ar'
                ? $course->requesting_entity_ar
                : $course->requesting_entity_en,
            'requesting_entity_en' => $course->requesting_entity_en,
            'requesting_entity_ar' => $course->requesting_entity_ar,
            'lecture_venue' => $course->lecture_venue,
        ];
    }

    /**
     * Room display data with the point-in-time snapshot name taking
     * precedence. Legacy entries (no snapshot) fall back to the live
     * relation; entries whose room was deleted keep their recorded name.
     * Roomless external-venue lectures return null.
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
