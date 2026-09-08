<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'available_hall_ids' => 'array',
        'available_lab_ids' => 'array',
    ];

    public function entries()
    {
        return $this->hasMany(ScheduleEntry::class);
    }

    public function sourceSchedule()
    {
        return $this->belongsTo(self::class, 'source_schedule_id');
    }

    public function derivedSchedules()
    {
        return $this->hasMany(self::class, 'source_schedule_id');
    }

    public function reservedPeriod(): ?array
    {
        if (
            ! $this->reserved_period_day
            || ! $this->reserved_period_start_time
            || ! $this->reserved_period_end_time
        ) {
            return null;
        }

        return [
            'day' => $this->reserved_period_day,
            'start_time' => substr((string) $this->reserved_period_start_time, 0, 5),
            'end_time' => substr((string) $this->reserved_period_end_time, 0, 5),
            'label_ar' => $this->reserved_period_label_ar,
        ];
    }
}
