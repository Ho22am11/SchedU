<?php

namespace App\Http\Resources;

use App\Models\ExternalCourseStaff;
use App\Models\Lap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExternalCourseResource extends JsonResource
{
    /**
     * Full payload: identity and variant shape for the UI, plus the eligible
     * labs and staff distributions (with staff names) the generation engine
     * consumes.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name_en' => $this->name_en,
            'name_ar' => $this->name_ar,
            'requesting_entity_en' => $this->requesting_entity_en,
            'requesting_entity_ar' => $this->requesting_entity_ar,

            'lab_groups' => $this->lab_groups,
            'lab_students_per_group' => $this->lab_students_per_group,
            'lecture_groups' => $this->lecture_groups,
            'lecture_students_per_group' => $this->lecture_students_per_group,
            'lecture_venue' => $this->lecture_venue,

            // Reserved-period override: only this course's sessions (its
            // assigned staff) may sit in the reserved period.
            'allow_reserved_period' => (bool) $this->allow_reserved_period,

            // Scheduling configuration requested by the outside faculty:
            // per-session span in hours and the allowed 2h grid slots
            // (null = anywhere on the grid).
            'lab_session_hours' => $this->lab_session_hours,
            'lecture_session_hours' => $this->lecture_session_hours,
            'lab_time_slots' => $this->lab_time_slots,
            'lecture_time_slots' => $this->lecture_time_slots,

            'eligible_labs' => $this->whenLoaded('eligibleLabs', function () {
                return $this->eligibleLabs->map(fn (Lap $lap) => [
                    'id' => $lap->id,
                    'name' => $lap->name,
                    'capacity' => $lap->capacity,
                    'labType' => $lap->labType,
                ]);
            }),

            // Distribution rows in the CourseAssignment shape: role, the
            // assigned staff member, and how many groups they take.
            'staff' => $this->whenLoaded('staffAssignments', function () {
                return $this->staffAssignments->map(function (ExternalCourseStaff $assignment) {
                    return [
                        'staff_id' => $assignment->staff_id,
                        'role' => $assignment->role,
                        'num_of_groups' => $assignment->num_of_groups,
                        'name' => $assignment->staff?->name,
                        'name_ar' => $assignment->staff?->name_ar,
                    ];
                });
            }),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
