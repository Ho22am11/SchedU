<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
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
            'source_schedule_id' => 'nullable|exists:schedules,id',

            // The room pool this schedule's generation was allowed to use.
            'available_hall_ids' => 'nullable|array',
            'available_hall_ids.*' => 'integer|distinct|exists:halls,id',
            'available_lab_ids' => 'nullable|array',
            'available_lab_ids.*' => 'integer|distinct|exists:laps,id',

            'reserved_period' => 'nullable|array',
            'reserved_period.day' => 'required_with:reserved_period|in:sunday,monday,tuesday,wednesday,thursday',
            'reserved_period.start_time' => 'required_with:reserved_period|date_format:H:i',
            'reserved_period.end_time' => 'required_with:reserved_period|date_format:H:i|after:reserved_period.start_time',
            'schedule' => 'required|array|min:1',

            'schedule.*.course_ids' => 'required|array|min:1',
            'schedule.*.course_ids.*' => 'required|exists:courses,id',

            'schedule.*.session_type' => 'required|in:lecture,lab',

            'schedule.*.group_info' => 'required|array',
            'schedule.*.group_info.group_number' => 'required|integer|min:1',
            'schedule.*.group_info.total_groups' => 'required|integer|min:1',

            'schedule.*.hall_id' => 'nullable|exists:halls,id',
            'schedule.*.lab_id' => 'nullable|exists:laps,id',

            'schedule.*.lecturer_id' => 'required|exists:lecturers,id',

            'schedule.*.time_slot' => 'required|array',
            'schedule.*.time_slot.day' => 'required|in:saturday,sunday,monday,tuesday,wednesday,thursday',
            'schedule.*.time_slot.start_time' => 'required|date_format:H:i',
            'schedule.*.time_slot.end_time' => 'required|date_format:H:i|after:schedule.*.time_slot.start_time',

            'schedule.*.student_count' => 'required|integer|min:1',
            // Multiple academic IDs
            'schedule.*.academic_ids' => 'required|array|min:1',
            'schedule.*.academic_ids.*' => 'required|exists:academics,id',

            // Multiple academic levels
            'schedule.*.academic_levels' => 'required|array|min:1',
            'schedule.*.academic_levels.*' => 'required|integer|min:1',

            // Multiple department IDs
            'schedule.*.department_ids' => 'required|array|min:1',
            'schedule.*.department_ids.*' => 'required|exists:departments,id',
        ];
    }

    public function messages(): array
    {
        return [
            'nameEn.required' => 'The English name is required.',
            'nameAr.required' => 'The Arabic name is required.',
            'source_schedule_id.exists' => 'Source schedule not found.',
            'schedule.required' => 'At least one schedule entry is required.',
            'schedule.array' => 'Schedule must be an array.',

            'schedule.*.course_ids.required' => 'Course IDs are required.',
            'schedule.*.course_ids.array' => 'Course IDs must be an array.',
            'schedule.*.course_ids.min' => 'At least one course ID is required.',
            'schedule.*.course_ids.*.exists' => 'One or more courses do not exist.',

            'schedule.*.session_type.required' => 'Session type is required.',
            'schedule.*.session_type.in' => 'Session type must be lecture or lab.',

            'schedule.*.group_info.required' => 'Group info is required.',
            'schedule.*.group_info.group_number.required' => 'Group number is required.',
            'schedule.*.group_info.total_groups.required' => 'Total groups is required.',

            'schedule.*.hall_id.exists' => 'Hall not found.',
            'schedule.*.lab_id.exists' => 'Lab not found.',

            'schedule.*.lecturer_id.required' => 'Lecturer is required.',
            'schedule.*.lecturer_id.exists' => 'Lecturer not found.',

            'schedule.*.time_slot.required' => 'Time slot is required.',
            'schedule.*.time_slot.day.required' => 'Day is required.',
            'schedule.*.time_slot.day.in' => 'Day must be a valid weekday.',
            'schedule.*.time_slot.start_time.required' => 'Start time is required.',
            'schedule.*.time_slot.start_time.date_format' => 'Start time must be in HH:MM format.',
            'schedule.*.time_slot.end_time.required' => 'End time is required.',
            'schedule.*.time_slot.end_time.date_format' => 'End time must be in HH:MM format.',
            'schedule.*.time_slot.end_time.after' => 'End time must be after start time.',

            'schedule.*.student_count.required' => 'Student count is required.',

            'schedule.*.academic_ids.required' => 'Academic IDs are required.',
            'schedule.*.academic_ids.array' => 'Academic IDs must be an array.',
            'schedule.*.academic_ids.min' => 'At least one academic ID is required.',
            'schedule.*.academic_ids.*.exists' => 'One or more academics do not exist.',

            'schedule.*.academic_levels.required' => 'Academic levels are required.',
            'schedule.*.academic_levels.array' => 'Academic levels must be an array.',
            'schedule.*.academic_levels.min' => 'At least one academic level is required.',

            'schedule.*.department_ids.required' => 'Department IDs are required.',
            'schedule.*.department_ids.array' => 'Department IDs must be an array.',
            'schedule.*.department_ids.min' => 'At least one department ID is required.',
            'schedule.*.department_ids.*.exists' => 'One or more departments do not exist.',
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
