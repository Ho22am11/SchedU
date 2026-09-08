<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class SwapEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $scheduleId = $this->route('schedule');

        return [
            'target_entry_id' => [
                'required',
                'integer',
                Rule::exists('schedule_entries', 'id')->where(function ($query) use ($scheduleId) {
                    $query->where('schedule_id', $scheduleId);
                }),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'target_entry_id.required' => 'The target entry is required.',
            'target_entry_id.exists' => 'The target entry does not belong to this schedule.',
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
