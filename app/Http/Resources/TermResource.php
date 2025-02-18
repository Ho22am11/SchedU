<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TermResource extends JsonResource
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

        return array_merge($data , [
            'term_item' => TermItemResource::collection($sortedTermItems)
        ]);
    }
}
