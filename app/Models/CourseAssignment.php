<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseAssignment extends Model
{
    use HasFactory;

    protected $guarded=['id'];
    public $timestamps = false ;


    public function lecturerAssignments()
    {
        return $this->hasMany(LecturerAssignment::class);
    }

    public function preferredLabs()
    {
        return $this->belongsToMany(Lap::class, 'lab_assignments', 'course_assignment_id', 'lab_id');
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }
   
}
