<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TermItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'term_id' => 'required|exists:term_plans,id',
            'course_id' => 'required|exists:courses,id',
            'lecturer_id' => [
                'required',
                'exists:lecturers,id',
                function ($attribute, $value, $fail) {
                    $conflict = \App\Models\Term_item::where('lecturer_id', $value)
                        ->where('day', $this->day)
                        ->where(function ($query) {
                            $query->whereBetween('start_time', [$this->start_time, $this->end_time])
                                  ->orWhereBetween('end_time', [$this->start_time, $this->end_time])
                                  ->orWhere(function ($query) {
                                      $query->where('start_time', '<=', $this->start_time)
                                            ->where('end_time', '>=', $this->end_time);
                                  });
                        })
                        ->exists();
    
                    if ($conflict) {
                        $fail('The lecturer already has another class at this time.');
                    }
                },
            ],
            'spaces_id' => [
                'required',
                'exists:acadmic_spaces,id',
                function ($attribute, $value, $fail) {
                    $conflict = \App\Models\Term_item::where('spaces_id', $value)
                        ->where('day', $this->day)
                        ->where(function ($query) {
                            $query->whereBetween('start_time', [$this->start_time, $this->end_time])
                                  ->orWhereBetween('end_time', [$this->start_time, $this->end_time])
                                  ->orWhere(function ($query) {
                                      $query->where('start_time', '<=', $this->start_time)
                                            ->where('end_time', '>=', $this->end_time);
                                  });
                        })
                        ->exists();
    
                    if ($conflict) {
                        $fail('The selected space is already occupied at this time.');
                    }
                },
            ],
            'start_time' => 'required|integer|min:0|max:2359',
            'end_time' => 'required|integer|min:0|max:2359|gt:start_time',
            'day' => 'required|integer|between:1,7',
            'type' => 'required|boolean',
        ];
    }
}
