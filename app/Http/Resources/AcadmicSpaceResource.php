<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcadmicSpaceResource extends JsonResource
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

        unset($data['term_item']);

        return array_merge($data , [
            'availability' => $this->MapAvailability($this->availability),
            'schedule' => TermItemResource::collection($sortedTermItems) 
        ]);
    }

    public function MapAvailability($value){
        $availability = [ 0 => 'availability' , 1 => 'basy'] ;
        return $availability[$value];
    }
}
