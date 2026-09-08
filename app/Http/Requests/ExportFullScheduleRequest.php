<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExportFullScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'schedule_id' => ['required', 'integer', 'exists:schedules,id'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['required', 'distinct', 'in:halls,labs,lecturers,teaching_assistants,academic_lists'],
        ];
    }
}
