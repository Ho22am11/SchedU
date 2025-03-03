<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Hall extends Model
{
    use HasFactory;
    protected $guarded=['id'];
    public $timestamps = false ;


    protected $fillable = ['id', 'name', 'capacity'];

    public function timePreferences()
    {
        return $this->morphMany(TimePreference::class, 'timeable');
    }
   
}
