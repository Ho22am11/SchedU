<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Academic extends Model
{
    use HasFactory;


    // protected $fillable =[''];
   // if the name of the table is different from the model name

    // protected $table="";



   //? to make all fields mass assignable else id
    protected $guarded=['id'];
    public $timestamps = false ;

 

    public function courses()
    {
        return $this->belongsToMany(Course::class, 'academic_items', 'academic_id', 'course_id');
    }
   
}
