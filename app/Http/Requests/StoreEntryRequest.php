<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator as TypedValidator;

/**
 * Adding a missing block (from the engine's pending-blocks scan) as a new
 * schedule entry. Field shapes mirror what the engine's scan serializer
 * emits, so the frontend forwards them unchanged.
 */
class StoreEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entry_kind' => 'required|in:course,external',
            'external_course_id' => 'nullable|integer|exists:external_courses,id',
            'course_ids' => 'nullable|array',
            'course_ids.*' => 'required|integer|exists:courses,id',
            'session_type' => 'required|in:lecture,lab',
            'group_number' => 'required|integer|min:1|max:255',
            'total_groups' => 'required|integer|min:1|max:255|gte:group_number',
            'day' => 'required|in:saturday,sunday,monday,tuesday,wednesday,thursday',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            // Exactly one room — except the roomless external-venue lecture.
            'hall_id' => 'nullable|integer|exists:halls,id',
            'lab_id' => 'nullable|integer|exists:laps,id',
            'requires_room' => 'nullable|boolean',
            'lecturer_id' => 'nullable|integer|exists:lecturers,id',
            'student_count' => 'required|integer|min:0',
            'academic_ids' => 'nullable|array',
            'academic_ids.*' => 'required|integer|exists:academics,id',
            'academic_levels' => 'nullable|array',
            'academic_levels.*' => 'required|integer|min:1|max:8',
            'department_ids' => 'nullable|array',
            'department_ids.*' => 'required|integer|exists:departments,id',
            // Opaque identity of the pending block being added; forwarded to
            // the engine so its cached state can find the block to seed.
            'block_key' => 'required|string',
        ];
    }

    public function withValidator(TypedValidator $validator): void
    {
        $validator->after(function (TypedValidator $validator) {
            // Source exclusivity by kind, tolerating the engine's empty
            // arrays (same semantics as StoreScheduleRequest).
            if ($this->input('entry_kind') === 'external') {
                if (empty($this->input('external_course_id'))) {
                    $validator->errors()->add(
                        'external_course_id',
                        'An external entry must reference its external course.'
                    );
                }
                if (! empty($this->input('course_ids'))) {
                    $validator->errors()->add(
                        'course_ids',
                        'An external entry must not reference local courses.'
                    );
                }
            } elseif (empty($this->input('course_ids'))) {
                $validator->errors()->add(
                    'course_ids',
                    'A course entry must reference at least one course.'
                );
            } elseif (! empty($this->input('external_course_id'))) {
                $validator->errors()->add(
                    'external_course_id',
                    'A course entry must not reference an external course.'
                );
            }

            $roomlessExternal = $this->input('entry_kind') === 'external'
                && $this->boolean('requires_room') === false;

            // Room presence is a structural shape: exactly one room, except
            // the roomless external-venue lecture. Whether a session may sit
            // in a given room (hall vs lab, specialization, hours) is the
            // engine's call — generation itself places requirement-free lab
            // sessions in halls, so a stricter PHP rule would drift from it.
            if (! $roomlessExternal && empty($this->input('hall_id')) && empty($this->input('lab_id'))) {
                $validator->errors()->add(
                    'hall_id',
                    'A session must be placed in a hall or a lab.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'entry_kind.in' => 'Entry kind must be course or external.',
            'group_number.gte' => 'Group number cannot exceed the total number of groups.',
            'day.in' => 'Day must be a valid weekday.',
            'start_time.date_format' => 'Start time must be in HH:MM format.',
            'end_time.date_format' => 'End time must be in HH:MM format.',
            'end_time.after' => 'End time must be after start time.',
            'block_key.required' => 'The pending block identity is required.',
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
