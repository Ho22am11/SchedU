<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimePreference extends Model
{
    use HasFactory;

    protected $guarded=['id'];
    public $timestamps = false ;


    protected $fillable = ['day', 'start_time', 'end_time'];

    public function timeable()
    {
        return $this->morphTo();
    }
   
}
