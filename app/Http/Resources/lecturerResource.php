<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class lecturerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        $data = parent::toArray($request);

        $sortedTermItems = $this->termItem->sortBy(function ($item) {
            $order = [7, 1, 2, 3, 4, 5, 6];  
            return array_search($item->day, $order);
        });

        unset($data['term_item'] , $data['department_id']);
        return array_merge($data ,[
        'department_namr' => $this->department->name ,
        'acdemic_degree' => $this->MapAcademicDegree($this->acdemic_degree) ,
        'type' => $this->MapType($this->type),
        'schedule' => TermItemResource::collection($sortedTermItems) 
     ]);

    }

    public function MapAcademicDegree($value){
        $acdemic_degree =[ 1 => 'professor' , 2 => 'associate_professor' , 3 => 'assistant_professor' , 4 => 'assistant_lecturer', 5 => 'teaching_assistant' ] ;
        return $acdemic_degree[$value];
    }

    public function MapType($value){
        $type = [ 0 => 'appointed' , 1 => 'seconded' ];
        return $type[$value];
    }
}
