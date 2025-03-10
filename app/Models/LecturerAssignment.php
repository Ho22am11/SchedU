<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LecturerAssignment extends Model
{
    use HasFactory;

    protected $guarded=['id'];
    public $timestamps = false ;

    public function lecturer()
{
    return $this->belongsTo(Lecturer::class);
}



   
}
