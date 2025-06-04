<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduleEntry extends Model
{
    use HasFactory;

    protected $guarded=['id'];

     public function course(){
        return $this->belongsTo(Course::class);
    }

     public function hall(){
        return $this->belongsTo(Hall::class);
    }

    public function lab(){
        return $this->belongsTo(Lap::class);
    }

    public function Lecturer(){
        return $this->belongsTo(Lecturer::class);
    }

    public function academic(){
        return $this->belongsTo(Academic::class);
    }

     public function department(){
        return $this->belongsTo(Department::class);
    }
   
}
