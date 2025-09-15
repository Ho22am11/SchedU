<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseAssignment extends Model
{
    use HasFactory;

    protected $guarded = ['id'];
    public $timestamps = false;


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

    public function studyPlan()
    {
        return $this->belongsTo(StudyPlane::class, 'study_plan_id');
    }

    // Common course relationships
    public function commonStudyPlan()
    {
        return $this->belongsTo(StudyPlane::class, 'common_study_plan_id');
    }

    public function commonCourse()
    {
        return $this->belongsTo(CourseAssignment::class, 'common_course_id');
    }
}
