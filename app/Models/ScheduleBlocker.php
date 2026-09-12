<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduleBlocker extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $fillable = ['schedule_id', 'lecturer_id', 'day', 'startTime', 'endTime', 'label'];

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }

    public function lecturer()
    {
        return $this->belongsTo(Lecturer::class);
    }
}
