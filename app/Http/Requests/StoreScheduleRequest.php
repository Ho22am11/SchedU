<?php

namespace App\Http\Requests;

use App\Models\ExternalCourse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator as ValidationValidator;

class StoreScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
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

            // Source discriminator: absent/null/anything-but-"external" keeps
            // the historical local-course behaviour untouched.
            'schedule.*.entry_kind' => 'nullable|in:course,external',

            'schedule.*.session_type' => 'required|in:lecture,lab',

            'schedule.*.group_info' => 'required|array',
            'schedule.*.group_info.group_number' => 'required|integer|min:1',
            'schedule.*.group_info.total_groups' => 'required|integer|min:1',

            'schedule.*.hall_id' => 'nullable|exists:halls,id',
            'schedule.*.lab_id' => 'nullable|exists:laps,id',

            'schedule.*.time_slot' => 'required|array',
            'schedule.*.time_slot.day' => 'required|in:saturday,sunday,monday,tuesday,wednesday,thursday',
            'schedule.*.time_slot.start_time' => 'required|date_format:H:i',
            'schedule.*.time_slot.end_time' => 'required|date_format:H:i|after:schedule.*.time_slot.start_time',

            'schedule.*.student_count' => 'required|integer|min:1',
        ];

        // Local-course fields are mandatory per entry; external entries
        // instead carry external_course_id, empty local arrays, and a
        // nullable lecturer. Wildcards cannot express "required only when
        // entry_kind = course", so the per-entry kind decides here.
        foreach ($this->input('schedule', []) as $index => $entry) {
            if (($entry['entry_kind'] ?? null) === 'external') {
                $rules["schedule.{$index}.external_course_id"] = 'required|integer|exists:external_courses,id';
                $rules["schedule.{$index}.course_ids"] = 'nullable|array';
            } else {
                $rules["schedule.{$index}.course_ids"] = 'required|array|min:1';
                $rules["schedule.{$index}.course_ids.*"] = 'required|exists:courses,id';
                $rules["schedule.{$index}.lecturer_id"] = 'required|exists:lecturers,id';

                // Multiple academic IDs
                $rules["schedule.{$index}.academic_ids"] = 'required|array|min:1';
                $rules["schedule.{$index}.academic_ids.*"] = 'required|exists:academics,id';

                // Multiple academic levels
                $rules["schedule.{$index}.academic_levels"] = 'required|array|min:1';
                $rules["schedule.{$index}.academic_levels.*"] = 'required|integer|min:1';

                // Multiple department IDs
                $rules["schedule.{$index}.department_ids"] = 'required|array|min:1';
                $rules["schedule.{$index}.department_ids.*"] = 'required|exists:departments,id';
            }
        }

        return $rules;
    }

    /**
     * Room and source rules that need the referenced external course:
     * exactly one source per entry, and the session's room shape must match
     * the component — labs always book a lab; lectures book a hall from the
     * run's pool, except external-venue lectures, which book no room at all
     * (the hall belongs to the outside faculty; SchedU reserves time and
     * lecturer only, so their lecturer_id is required and validated at the
     * course level).
     */
    public function withValidator(ValidationValidator $validator): void
    {
        $validator->after(function (ValidationValidator $validator) {
            $courses = [];

            foreach ($this->input('schedule', []) as $index => $entry) {
                $kind = $entry['entry_kind'] ?? 'course';
                $key = "schedule.{$index}";

                if ($kind === 'external') {
                    if (! empty($entry['course_ids'])) {
                        $validator->errors()->add(
                            "{$key}.course_ids",
                            'External entries must not reference local courses.'
                        );
                    }
                } elseif (! empty($entry['external_course_id'])) {
                    $validator->errors()->add(
                        "{$key}.external_course_id",
                        'A local course entry must not reference an external course.'
                    );
                }

                if ($kind !== 'external') {
                    continue;
                }

                $courseId = $entry['external_course_id'] ?? null;
                if ($courseId === null) {
                    continue; // the required rule already reported this
                }

                $course = $courses[$courseId] ??= ExternalCourse::find($courseId);
                if ($course === null) {
                    continue; // the exists rule already reported this
                }

                $this->validateExternalRoomRules($validator, $key, $course, $entry);
                $this->validateExternalTimeRules($validator, $key, $course, $entry);
            }
        });
    }

    /**
     * Time rules for external entries beyond the generic HH:MM checks: the
     * start must fall on the 2-hour teaching grid, the span must equal the
     * course's configured session hours for the component (a 1h component
     * packs two groups into one 2h slot), and when the course declares time
     * slots the whole session must sit inside one of them.
     */
    private function validateExternalTimeRules(
        ValidationValidator $validator,
        string $key,
        ExternalCourse $course,
        array $entry
    ): void {
        $day = $entry['time_slot']['day'] ?? null;
        $start = $entry['time_slot']['start_time'] ?? null;
        $end = $entry['time_slot']['end_time'] ?? null;

        if ($day === null || $start === null || $end === null) {
            return; // the generic rules already reported the shape
        }

        $startMinutes = $this->minutes($start);
        $endMinutes = $this->minutes($end);
        if ($startMinutes === null || $endMinutes === null) {
            return;
        }

        $isLab = ($entry['session_type'] ?? '') === 'lab';
        $sessionHours = $isLab ? $course->lab_session_hours : $course->lecture_session_hours;
        $spanMinutes = ($sessionHours ?? 2) * 60;

        // 2h sessions must start on a 2h grid start; a 1h session is a half
        // of some 2h slot, so any full hour inside the teaching day is legal.
        $allowedStarts = $spanMinutes === 60
            ? ['09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00']
            : ['09:00', '11:00', '13:00', '15:00', '17:00'];

        if (! in_array($start, $allowedStarts, true)) {
            $validator->errors()->add(
                "{$key}.time_slot.start_time",
                'Session start times must fall on the teaching grid (09:00-19:00).'
            );
        }

        if ($endMinutes - $startMinutes !== $spanMinutes) {
            $component = $isLab ? 'lab' : 'lecture';
            $validator->errors()->add(
                "{$key}.time_slot.end_time",
                "This course's {$component} sessions run ".($sessionHours ?? 2).' hour(s), not the submitted span.'
            );
        }

        $declared = $isLab ? $course->lab_time_slots : $course->lecture_time_slots;
        if (empty($declared)) {
            return;
        }

        $inside = false;
        foreach ($declared as $slot) {
            $slotStart = $this->minutes($slot['start_time'] ?? '');
            $slotEnd = $this->minutes($slot['end_time'] ?? '');

            if ($slotStart === null || $slotEnd === null) {
                continue;
            }

            if (($slot['day'] ?? null) === $day
                && $startMinutes >= $slotStart
                && $endMinutes <= $slotEnd
            ) {
                $inside = true;
                break;
            }
        }

        if (! $inside) {
            $validator->errors()->add(
                "{$key}.time_slot",
                'The requesting faculty fixed this component to specific time slots, so the session must sit inside one of them.'
            );
        }
    }

    private function minutes(string $time): ?int
    {
        $parts = explode(':', $time);

        if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            return null;
        }

        return ((int) $parts[0]) * 60 + (int) $parts[1];
    }

    private function validateExternalRoomRules(
        ValidationValidator $validator,
        string $key,
        ExternalCourse $course,
        array $entry
    ): void {
        $hallId = $entry['hall_id'] ?? null;
        $labId = $entry['lab_id'] ?? null;
        $sessionType = $entry['session_type'] ?? '';

        if ($sessionType === 'lab') {
            if (empty($labId)) {
                $validator->errors()->add("{$key}.lab_id", 'External lab sessions must be scheduled in a lab.');
            }
            if (! empty($hallId)) {
                $validator->errors()->add("{$key}.hall_id", 'External lab sessions cannot be booked into a hall.');
            }

            return;
        }

        if ($sessionType === 'lecture' && $course->lecture_venue === 'external') {
            if (! empty($hallId) || ! empty($labId)) {
                $validator->errors()->add(
                    "{$key}.hall_id",
                    'An external-venue lecture books no room — hall_id and lab_id must both be empty.'
                );
            }

            return;
        }

        if ($sessionType === 'lecture') {
            if (empty($hallId)) {
                $validator->errors()->add("{$key}.hall_id", 'External lectures in our halls must be booked into a hall.');
            }
            if (! empty($labId)) {
                $validator->errors()->add("{$key}.lab_id", 'External lectures cannot be booked into a lab.');
            }
        }
    }

    public function messages(): array
    {
        return [
            'nameEn.required' => 'The English name is required.',
            'nameAr.required' => 'The Arabic name is required.',
            'source_schedule_id.exists' => 'Source schedule not found.',
            'schedule.required' => 'At least one schedule entry is required.',
            'schedule.array' => 'Schedule must be an array.',

            'schedule.*.entry_kind.in' => 'Entry kind must be course or external.',

            'schedule.*.course_ids.required' => 'Course IDs are required.',
            'schedule.*.course_ids.array' => 'Course IDs must be an array.',
            'schedule.*.course_ids.min' => 'At least one course ID is required.',
            'schedule.*.course_ids.*.exists' => 'One or more courses do not exist.',

            'schedule.*.external_course_id.required' => 'External course ID is required on external entries.',
            'schedule.*.external_course_id.exists' => 'External course not found.',

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
