<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExternalCourse extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'lab_groups' => 'integer',
        'lab_students_per_group' => 'integer',
        'lecture_groups' => 'integer',
        'lecture_students_per_group' => 'integer',
        'lab_session_hours' => 'integer',
        'lecture_session_hours' => 'integer',
        'lab_time_slots' => 'array',
        'lecture_time_slots' => 'array',
        'allow_reserved_period' => 'boolean',
    ];

    /**
     * Internal staff distributions per component: lecturer rows staff the
     * lecture groups, ta rows the lab groups. An empty collection for a
     * component means that component runs without internal staff.
     */
    public function staffAssignments()
    {
        return $this->hasMany(ExternalCourseStaff::class);
    }

    /**
     * Labs the course's lab groups may be scheduled in; empty means the
     * engine's general lab pool. There is no hall equivalent on purpose —
     * lectures in our halls draw from the run's hall pool.
     */
    public function eligibleLabs()
    {
        return $this->belongsToMany(Lap::class, 'external_course_lap');
    }

    /**
     * Display sugar for read surfaces: the staff assignments when the course
     * carries any internal staff, null when fully external (no distributions
     * at all).
     */
    public function getInternalStaffAttribute()
    {
        $assignments = $this->staffAssignments;

        return $assignments->isEmpty() ? null : $assignments;
    }

    /**
     * True when the lecture component exists but no lecturer distribution
     * covers it — the "external lecturer" case (v5 when the venue is ours).
     */
    public function hasExternalLecturer(): bool
    {
        return $this->lecture_groups > 0
            && $this->staffAssignments
                ->where('role', 'lecturer')
                ->isEmpty();
    }
}
