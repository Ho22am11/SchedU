<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreScheduleRequest extends FormRequest
{
       public function authorize(): bool
    {
        return true; 
    }

    public function rules(): array
    {
        return [
            'nameEn' => 'required|string|max:255',
            'nameAr' => 'required|string|max:255',
            'entries' => 'required|array|min:1',

            'entries.*.course_id' => 'required|exists:courses,id',
            'entries.*.session_type' => 'required|in:lecture,lab',
            'entries.*.group_number' => 'required|integer|min:1',
            'entries.*.total_groups' => 'required|integer|min:1',
            'entries.*.hall_id' => 'nullable|exists:halls,id',
            'entries.*.lap_id' => 'nullable|exists:labs,id',
            'entries.*.lecturer_id' => 'required',
            'entries.*.Day' => 'required|in:saturday,sunday,monday,tuesday,wednesday,thursday',
            'entries.*.startTime' => 'required|date_format:H:i',
            'entries.*.endTime' => 'required|date_format:H:i|after:entries.*.startTime',
            'entries.*.student_count' => 'required|integer|min:1',
            'entries.*.academic_id' => 'required',
            'entries.*.academic_level' => 'required|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'nameEn.required' => 'The English name is required.',
            'nameAr.required' => 'The Arabic name is required.',
            'entries.required' => 'At least one schedule entry is required.',
            'entries.array' => 'Entries must be an array.',

            'entries.*.course_id.required' => 'Course is required.',
            'entries.*.course_id.exists' => 'Course does not exist.',
            'entries.*.session_type.required' => 'Session type is required.',
            'entries.*.session_type.in' => 'Session type must be either lecture or lab.',
            'entries.*.group_number.required' => 'Group number is required.',
            'entries.*.total_groups.required' => 'Total groups is required.',
            'entries.*.hall_id.exists' => 'Hall not found.',
            'entries.*.lap_id.exists' => 'Lab not found.',
            'entries.*.lecturer_id.required' => 'Lecturer is required.',
            'entries.*.lecturer_id.exists' => 'Lecturer not found.',
            'entries.*.Day.required' => 'Day is required.',
            'entries.*.Day.in' => 'Day must be a valid weekday.',
            'entries.*.startTime.required' => 'Start time is required.',
            'entries.*.startTime.date_format' => 'Start time must be in HH:MM format.',
            'entries.*.endTime.required' => 'End time is required.',
            'entries.*.endTime.date_format' => 'End time must be in HH:MM format.',
            'entries.*.endTime.after' => 'End time must be after start time.',
            'entries.*.student_count.required' => 'Student count is required.',
            'entries.*.academic_id.required' => 'Academic list is required.',
            'entries.*.academic_id.exists' => 'Academic list not found.',
            'entries.*.academic_level.required' => 'Academic level is required.',
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
