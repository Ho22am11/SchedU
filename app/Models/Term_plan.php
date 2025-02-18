<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Term_plan extends Model
{
    use HasFactory;


    // protected $fillable =[''];
   // if the name of the table is different from the model name

    // protected $table="";



   //? to make all fields mass assignable else id
    protected $guarded=['id'];
    public $timestamps = false ;

    public function termItem()
    {
        return $this->hasMany(Term_item::class, 'term_id');
    }
    
   
}
