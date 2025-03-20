<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lap extends Model
{
    use HasFactory;
    public $timestamps = false ;

    protected $guarded=['id'];

    protected $fillable = [
        'name', 
        'capacity', 
        'labType', 
        'usedInNonSpecialistCourses'
    ];
   
    public function availability()
    {
        return $this->morphMany(TimePreference::class, 'timeable');
    }
}
