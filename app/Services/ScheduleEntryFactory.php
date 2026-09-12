<?php

namespace App\Services;

use App\Models\ExternalCourse;
use App\Models\Hall;
use App\Models\Lap;
use App\Models\Lecturer;

/**
 * Builds schedule_entries attributes in the engine's posted shape, resolving
 * the point-in-time name snapshots (hall/lab/lecturer, plus the external
 * course's display fields) every row carries.
 *
 * Shared by the generation store path (bulk, name sources pre-fetched once
 * for the whole batch) and the editor's single-entry add endpoint.
 */
class ScheduleEntryFactory
{
    /**
     * Pre-fetch the name sources for a batch of entries so bulk creation
     * issues four queries total instead of four per entry.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array{halls: \Illuminate\Support\Collection, laps: \Illuminate\Support\Collection, lecturers: \Illuminate\Support\Collection, externalCourses: \Illuminate\Support\Collection}
     */
    public static function nameSources(array $entries): array
    {
        return [
            'halls' => Hall::whereIn('id', collect($entries)->pluck('hall_id')->filter())
                ->pluck('name', 'id'),
            'laps' => Lap::whereIn('id', collect($entries)->pluck('lab_id')->filter())
                ->pluck('name', 'id'),
            'lecturers' => Lecturer::whereIn('id', collect($entries)->pluck('lecturer_id')->filter())
                ->get(['id', 'name', 'name_ar'])
                ->keyBy('id'),
            'externalCourses' => ExternalCourse::whereIn('id', collect($entries)->pluck('external_course_id')->filter())
                ->get([
                    'id',
                    'code',
                    'name_en',
                    'name_ar',
                    'requesting_entity_en',
                    'requesting_entity_ar',
                    'lecture_venue',
                ])
                ->keyBy('id'),
        ];
    }

    /**
     * Attribute array for one entry in the engine's posted shape
     * (`group_info`, `time_slot`, `lab_id`, ...) including its name snapshots.
     *
     * @param  array<string, mixed>  $entry
     * @param  array{halls: \Illuminate\Support\Collection, laps: \Illuminate\Support\Collection, lecturers: \Illuminate\Support\Collection, externalCourses: \Illuminate\Support\Collection}  $sources
     */
    public static function attributes(array $entry, array $sources): array
    {
        $isExternal = ($entry['entry_kind'] ?? 'course') === 'external';
        $lecturer = $sources['lecturers']->get($entry['lecturer_id'] ?? null);
        $externalCourse = $isExternal
            ? $sources['externalCourses']->get($entry['external_course_id'] ?? null)
            : null;

        return [
            'entry_kind' => $isExternal ? 'external' : 'course',
            'external_course_id' => $isExternal ? $entry['external_course_id'] : null,

            // Baked external-course identity: the display fields as they are
            // at write time, so editing or deleting the course never rewrites
            // schedule history (the id may dangle once the course is gone).
            'external_course_code' => $externalCourse?->code,
            'external_course_name_en' => $externalCourse?->name_en,
            'external_course_name_ar' => $externalCourse?->name_ar,
            'external_course_entity_en' => $externalCourse?->requesting_entity_en,
            'external_course_entity_ar' => $externalCourse?->requesting_entity_ar,
            'external_course_venue' => $externalCourse?->lecture_venue,

            'course_ids' => $entry['course_ids'] ?? [],
            'session_type' => $entry['session_type'],
            'group_number' => $entry['group_info']['group_number'],
            'total_groups' => $entry['group_info']['total_groups'],
            'hall_id' => $entry['hall_id'] ?? null,
            'hall_name' => $sources['halls'][$entry['hall_id'] ?? null] ?? null,
            'lap_id' => $entry['lab_id'] ?? null,     // JSON field is lab_id, the DB column is lap_id
            'lap_name' => $sources['laps'][$entry['lab_id'] ?? null] ?? null,
            'lecturer_id' => $entry['lecturer_id'] ?? null,
            'lecturer_name' => $lecturer?->name,
            'lecturer_name_ar' => $lecturer?->name_ar,
            'Day' => $entry['time_slot']['day'],
            'startTime' => $entry['time_slot']['start_time'],
            'endTime' => $entry['time_slot']['end_time'],
            'student_count' => $entry['student_count'],
            'academic_ids' => $entry['academic_ids'] ?? [],
            'academic_levels' => $entry['academic_levels'] ?? [],
            'department_ids' => $entry['department_ids'] ?? [],
        ];
    }
}
