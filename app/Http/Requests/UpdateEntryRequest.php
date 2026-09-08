<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'day' => 'required|in:saturday,sunday,monday,tuesday,wednesday,thursday',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            // Optional room move: only one of the two, matching the entry's type.
            'hall_id' => 'nullable|prohibits:lab_id|exists:halls,id',
            'lab_id' => 'nullable|prohibits:hall_id|exists:laps,id',
        ];
    }

    public function messages(): array
    {
        return [
            'day.required' => 'The day is required.',
            'day.in' => 'Day must be a valid weekday.',
            'start_time.required' => 'The start time is required.',
            'start_time.date_format' => 'Start time must be in HH:MM format.',
            'end_time.required' => 'The end time is required.',
            'end_time.date_format' => 'End time must be in HH:MM format.',
            'end_time.after' => 'End time must be after start time.',
            'hall_id.prohibits' => 'A hall and a lab cannot both be set.',
            'hall_id.exists' => 'Hall not found.',
            'lab_id.prohibits' => 'A hall and a lab cannot both be set.',
            'lab_id.exists' => 'Lab not found.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status' => false,
            'message' => 'Validation Error',
            'errors' => $validator->errors(),
        ], 422));
    }
}
