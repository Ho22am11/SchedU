<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExternalCourseStaff extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'num_of_groups' => 'integer',
    ];

    public function externalCourse()
    {
        return $this->belongsTo(ExternalCourse::class);
    }

    /**
     * The assigned staff member. Lecturers and teaching assistants both live
     * in the lecturers table; role says which component this person staffs.
     */
    public function staff()
    {
        return $this->belongsTo(Lecturer::class, 'staff_id');
    }
}
