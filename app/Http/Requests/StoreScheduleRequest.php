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
        'nameEn'                         => 'required|string|max:255',
        'nameAr'                         => 'required|string|max:255',
        'schedule'                       => 'required|array|min:1',

        'schedule.*.course_id'           => 'required|exists:courses,id',
        'schedule.*.session_type'        => 'required|in:lecture,lab',

        'schedule.*.group_info'                  => 'required|array',
        'schedule.*.group_info.group_number'     => 'required|integer|min:1',
        'schedule.*.group_info.total_groups'     => 'required|integer|min:1',

        'schedule.*.hall_id'            => 'nullable|exists:halls,id',
        'schedule.*.lab_id'             => 'nullable|exists:laps,id',

        'schedule.*.lecturer_id'        => 'required|exists:lecturers,id',

        'schedule.*.time_slot'                  => 'required|array',
        'schedule.*.time_slot.day'              => 'required|in:saturday,sunday,monday,tuesday,wednesday,thursday',
        'schedule.*.time_slot.start_time'       => 'required|date_format:H:i',
        'schedule.*.time_slot.end_time'         => 'required|date_format:H:i|after:schedule.*.time_slot.start_time',

        'schedule.*.student_count'      => 'required|integer|min:1',
        'schedule.*.academic_id'        => 'required|exists:academics,id',
        'schedule.*.academic_level'     => 'required|integer|min:1',

        'schedule.*.department_id'      => 'required|exists:departments,id',
    ];
}

public function messages(): array
{
    return [
        'nameEn.required'                             => 'The English name is required.',
        'nameAr.required'                             => 'The Arabic name is required.',
        'schedule.required'                           => 'At least one schedule entry is required.',
        'schedule.array'                              => 'Schedule must be an array.',

        'schedule.*.course_id.required'               => 'Course is required.',
        'schedule.*.course_id.exists'                 => 'Course does not exist.',
        'schedule.*.session_type.required'            => 'Session type is required.',
        'schedule.*.session_type.in'                  => 'Session type must be lecture or lab.',

        'schedule.*.group_info.required'              => 'Group info is required.',
        'schedule.*.group_info.group_number.required' => 'Group number is required.',
        'schedule.*.group_info.total_groups.required' => 'Total groups is required.',

        'schedule.*.hall_id.exists'                   => 'Hall not found.',
        'schedule.*.lab_id.exists'                    => 'Lab not found.',

        'schedule.*.lecturer_id.required'             => 'Lecturer is required.',
        'schedule.*.lecturer_id.exists'               => 'Lecturer not found.',

        'schedule.*.time_slot.required'               => 'Time slot is required.',
        'schedule.*.time_slot.day.required'           => 'Day is required.',
        'schedule.*.time_slot.day.in'                 => 'Day must be a valid weekday.',
        'schedule.*.time_slot.start_time.required'    => 'Start time is required.',
        'schedule.*.time_slot.start_time.date_format' => 'Start time must be in HH:MM format.',
        'schedule.*.time_slot.end_time.required'      => 'End time is required.',
        'schedule.*.time_slot.end_time.date_format'   => 'End time must be in HH:MM format.',
        'schedule.*.time_slot.end_time.after'         => 'End time must be after start time.',

        'schedule.*.student_count.required'           => 'Student count is required.',
        'schedule.*.academic_id.required'             => 'Academic is required.',
        'schedule.*.academic_id.exists'               => 'Academic not found.',
        'schedule.*.academic_level.required'          => 'Academic level is required.',

        'schedule.*.department_id.required'           => 'Department is required.',
        'schedule.*.department_id.exists'             => 'Department not found.',
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
