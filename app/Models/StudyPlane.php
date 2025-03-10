<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudyPlane extends Model
{
    use HasFactory;
    protected $guarded=['id'];
    public $timestamps = false ;


    public function courseAssignments()
    {
        return $this->hasMany(CourseAssignment::class, 'study_plan_id');
    }


    public function academic()
{
    return $this->belongsTo(Academic::class, 'academic_id');
}

    
   
}
