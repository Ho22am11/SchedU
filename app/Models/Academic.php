<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Academic extends Model
{
    use HasFactory;

    protected $guarded=['id'];
    public $timestamps = false ;

 

    public function courses()
    {
        return $this->hasMany(Course::class, 'academic_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }
   
}
