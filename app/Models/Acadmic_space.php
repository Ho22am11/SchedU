<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Acadmic_space extends Model
{
    use HasFactory;

       protected $guarded=['id'];
       public $timestamps = false ;

       public function termItem()
       {
        return $this->hasMany(Term_item::class , 'spaces_id') ;
       }
}
