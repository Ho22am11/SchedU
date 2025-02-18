<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TermItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
       

        $data = array_diff_key($data, array_flip(['term_id', 'course_id', 'lecturer_id', 'spaces_id']));

        return array_merge($data , [
            'term_name' => $this->term->name ,
            'course_name' => $this->course->name_en ,
            'lecturer_name' => $this->lecturer->name ,
            'spaces_name' => $this->spaces->name ,
            'day' => $this->MapDay($this->day),
            'type' => $this->MapType($this->type),

        ]);
        }

    public function MapDay($value){
        $days = [
            1 => 'Sunday',
            2 => 'Monday',
            3 => 'Tuesday',
            4 => 'Wednesday',
            5 => 'Thursday',
            6 => 'Friday',
            7 => 'Saturday',
        ];
        return $days[$value];

    }

    public function MapType($value){
        $type = [ 0 => 'Section ' , 1 => 'Lecture '] ;
        return $type[$value];

    }
}
