<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Term_item extends Model
{
    use HasFactory;


    // protected $fillable =[''];
   // if the name of the table is different from the model name

    // protected $table="";



   //? to make all fields mass assignable else id
    protected $guarded=['id'];
    public $timestamps = false ;

    public function term()
    {
        return $this->belongsTo(Term_plan::class);
    }
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function lecturer()
    {
        return $this->belongsTo(Lecturer::class);
    }

    public function spaces()
    {
        return $this->belongsTo(Acadmic_space::class);
    }
}
