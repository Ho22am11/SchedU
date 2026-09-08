<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduleEntry extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'course_ids' => 'array',
        'academic_ids' => 'array',
        'academic_levels' => 'array',
        'department_ids' => 'array',
    ];

    public function hall()
    {
        return $this->belongsTo(Hall::class);
    }

    public function lap()
    {
        return $this->belongsTo(Lap::class);
    }

    public function lab()
    {
        return $this->belongsTo(Lap::class);
    }

    public function Lecturer()
    {
        return $this->belongsTo(Lecturer::class);
    }

    /**
     * Re-record the point-in-time room names from the current hall_id/lap_id.
     * Called whenever a move or swap reassigns the room so the snapshot always
     * matches the entry's placement. At most one of the two rooms is set.
     */
    public function refreshRoomSnapshots(): void
    {
        $this->hall_name = $this->hall_id ? Hall::find($this->hall_id)?->name : null;
        $this->lap_name = $this->lap_id ? Lap::find($this->lap_id)?->name : null;
    }

    // Accessor methods instead of relationships for multiple IDs
    public function getCoursesAttribute()
    {
        if (! $this->course_ids || ! is_array($this->course_ids)) {
            return collect();
        }

        return Course::whereIn('id', $this->course_ids)->get();
    }

    public function getAcademicsAttribute()
    {
        if (! $this->academic_ids || ! is_array($this->academic_ids)) {
            return collect();
        }

        return Academic::whereIn('id', $this->academic_ids)->get();
    }

    public function getDepartmentsAttribute()
    {
        if (! $this->department_ids || ! is_array($this->department_ids)) {
            return collect();
        }

        return Department::whereIn('id', $this->department_ids)->get();
    }
}
