<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffBlocker extends Model
{
    use HasFactory;

    protected $guarded = ['id'];
    public $timestamps = false;

    protected $fillable = ['lecturer_id', 'day', 'startTime', 'endTime', 'label'];

    public function lecturer()
    {
        return $this->belongsTo(Lecturer::class);
    }
}
