<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreExternalCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'nullable|string|max:255',

            'name_en' => 'required|string|max:255',
            'name_ar' => 'required|string|max:255',
            'requesting_entity_en' => 'required|string|max:255',
            'requesting_entity_ar' => 'required|string|max:255',

            'lab_groups' => 'required|integer|min:0|max:255',
            'lab_students_per_group' => 'nullable|integer|min:1',
            'lecture_groups' => 'required|integer|min:0|max:255',
            'lecture_students_per_group' => 'nullable|integer|min:1',
            'lecture_venue' => ['nullable', Rule::in(['ours', 'external'])],

            // Reserved-period override: only this course's sessions (its
            // assigned staff) may sit in the reserved period.
            'allow_reserved_period' => 'nullable|boolean',

            // Scheduling configuration: per-session span (1h packs two groups
            // into one 2h slot) and the allowed 2h grid slots (null/absent =
            // anywhere). Shape cross-checks live in withValidator.
            'lab_session_hours' => ['nullable', Rule::in([1, 2])],
            'lecture_session_hours' => ['nullable', Rule::in([1, 2])],

            'lab_time_slots' => 'nullable|array',
            'lab_time_slots.*.day' => ['required', Rule::in($this->schedulingDays())],
            'lab_time_slots.*.start_time' => 'required|date_format:H:i',
            'lab_time_slots.*.end_time' => 'required|date_format:H:i|after:lab_time_slots.*.start_time',

            'lecture_time_slots' => 'nullable|array',
            'lecture_time_slots.*.day' => ['required', Rule::in($this->schedulingDays())],
            'lecture_time_slots.*.start_time' => 'required|date_format:H:i',
            'lecture_time_slots.*.end_time' => 'required|date_format:H:i|after:lecture_time_slots.*.start_time',

            'eligible_lab_ids' => 'nullable|array',
            'eligible_lab_ids.*' => 'integer|distinct|exists:laps,id',

            'staff' => 'nullable|array',
            'staff.*.staff_id' => 'required|integer|exists:lecturers,id',
            'staff.*.role' => ['required', Rule::in(['lecturer', 'ta'])],
            'staff.*.num_of_groups' => 'required|integer|min:1|max:255',
        ];
    }

    /**
     * The variant rules: a course must request at least one component; each
     * present component carries its students-per-group and (for lectures) a
     * venue; a staff distribution covers every group of its component
     * exactly — never partially — and lecturers are required when the
     * lecture's venue is external, because a roomless staffless lecture
     * would reserve nothing at all.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $labGroups = (int) $this->input('lab_groups', 0);
            $lectureGroups = (int) $this->input('lecture_groups', 0);

            if ($labGroups === 0 && $lectureGroups === 0) {
                $validator->errors()->add(
                    'lab_groups',
                    'An external course must request at least one component (lab groups or lecture groups).'
                );

                return;
            }

            if ($labGroups > 0 && ! $this->filled('lab_students_per_group')) {
                $validator->errors()->add(
                    'lab_students_per_group',
                    'Lab students per group is required when the course has lab groups.'
                );
            }

            if ($lectureGroups > 0) {
                if (! $this->filled('lecture_students_per_group')) {
                    $validator->errors()->add(
                        'lecture_students_per_group',
                        'Lecture students per group is required when the course has lecture groups.'
                    );
                }

                if (! $this->filled('lecture_venue')) {
                    $validator->errors()->add(
                        'lecture_venue',
                        'Lecture venue is required when the course has lecture groups.'
                    );
                }
            } elseif ($this->filled('lecture_venue')) {
                $validator->errors()->add(
                    'lecture_venue',
                    'Lecture venue must be empty when the course has no lecture groups.'
                );
            }

            $this->validateStaffDistribution($validator, $labGroups, $lectureGroups);

            // Session hours are deliberately NOT rejected when their component
            // is absent: a previous save stores the default 2h, and rejecting
            // the leftover value would make removing the component impossible.
            // The controller normalizes them to null instead.

            $this->validateTimeSlots($validator, 'lab_time_slots', $labGroups > 0, 'lab');
            $this->validateTimeSlots($validator, 'lecture_time_slots', $lectureGroups > 0, 'lecture');
        });
    }

    /**
     * The days SchedU schedules on — the same list the schedule-entry
     * validation accepts.
     *
     * @return list<string>
     */
    private function schedulingDays(): array
    {
        return ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday'];
    }

    /**
     * A declared slot set is a hard restriction for the engine, so its shape
     * must be provably usable: slots only on a component that exists, whole
     * 2h grid periods (start 09:00-17:00 2h-stepped, end = start + 2h), and
     * no duplicate slots.
     */
    private function validateTimeSlots(Validator $validator, string $field, bool $componentPresent, string $component): void
    {
        $slots = $this->input($field);

        if (empty($slots)) {
            return;
        }

        if (! $componentPresent) {
            $validator->errors()->add(
                $field,
                ucfirst($component)." time slots are not allowed when the course has no {$component} groups."
            );

            return;
        }

        $gridStarts = ['09:00', '11:00', '13:00', '15:00', '17:00'];
        $seen = [];

        foreach ($slots as $index => $slot) {
            $start = $slot['start_time'] ?? null;
            $end = $slot['end_time'] ?? null;
            $day = $slot['day'] ?? null;

            if ($start === null || $end === null || $day === null) {
                continue; // the field rules already reported the shape
            }

            if (! in_array($start, $gridStarts, true)) {
                $validator->errors()->add(
                    "{$field}.{$index}.start_time",
                    "Slot start times must fall on the 2-hour teaching grid (09:00-17:00), got {$start}."
                );
            }

            $startMinutes = $this->minutes($start);
            $endMinutes = $this->minutes($end);

            if ($startMinutes !== null && $endMinutes !== null && $endMinutes - $startMinutes !== 120) {
                $validator->errors()->add(
                    "{$field}.{$index}.end_time",
                    'Each slot must span exactly 2 hours (one whole grid period).'
                );
            }

            $key = $day.'|'.$start;
            if (isset($seen[$key])) {
                $validator->errors()->add(
                    "{$field}.{$index}.day",
                    'The same time slot is declared twice.'
                );
            }
            $seen[$key] = true;
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

    private function validateStaffDistribution(Validator $validator, int $labGroups, int $lectureGroups): void
    {
        $staff = $this->input('staff', []) ?? [];

        $taGroups = 0;
        $lecturerGroups = 0;
        $seen = [];

        foreach ($staff as $index => $assignment) {
            $role = $assignment['role'] ?? '';
            $staffId = $assignment['staff_id'] ?? null;
            $key = $role.'|'.$staffId;

            if (isset($seen[$key])) {
                $validator->errors()->add(
                    "staff.{$index}.staff_id",
                    'The same staff member is assigned twice for the same role.'
                );
            }
            $seen[$key] = true;

            if ($role === 'ta') {
                if ($labGroups === 0) {
                    $validator->errors()->add(
                        "staff.{$index}.role",
                        'TA assignments are not allowed on a course without lab groups.'
                    );

                    continue;
                }
                $taGroups += (int) ($assignment['num_of_groups'] ?? 0);
            }

            if ($role === 'lecturer') {
                if ($lectureGroups === 0) {
                    $validator->errors()->add(
                        "staff.{$index}.role",
                        'Lecturer assignments are not allowed on a course without lecture groups.'
                    );

                    continue;
                }
                $lecturerGroups += (int) ($assignment['num_of_groups'] ?? 0);
            }
        }

        if ($taGroups > 0 && $taGroups !== $labGroups) {
            $validator->errors()->add(
                'staff',
                "TA assignments must cover every lab group exactly (assigned {$taGroups} of {$labGroups} groups). Partial coverage is not allowed."
            );
        }

        if ($lecturerGroups > 0 && $lecturerGroups !== $lectureGroups) {
            $validator->errors()->add(
                'staff',
                "Lecturer assignments must cover every lecture group exactly (assigned {$lecturerGroups} of {$lectureGroups} groups). Partial coverage is not allowed."
            );
        }

        if (
            $lectureGroups > 0
            && $this->input('lecture_venue') === 'external'
            && $lecturerGroups === 0
        ) {
            $validator->errors()->add(
                'staff',
                'A lecture at an external venue still reserves its internal lecturer\'s time, so at least one lecturer assignment is required.'
            );
        }
    }

    public function messages(): array
    {
        return [
            'name_en.required' => 'The English name is required.',
            'name_ar.required' => 'The Arabic name is required.',
            'requesting_entity_en.required' => 'The English requesting entity is required.',
            'requesting_entity_ar.required' => 'The Arabic requesting entity is required.',
            'lab_groups.required' => 'Lab groups is required (0 when the course has no lab component).',
            'lab_groups.integer' => 'Lab groups must be a number.',
            'lab_groups.min' => 'Lab groups cannot be negative.',
            'lecture_groups.required' => 'Lecture groups is required (0 when the course has no lecture component).',
            'lecture_groups.integer' => 'Lecture groups must be a number.',
            'lecture_groups.min' => 'Lecture groups cannot be negative.',
            'lecture_venue.in' => 'Lecture venue must be "ours" or "external".',
            'lab_students_per_group.min' => 'Lab students per group must be at least 1.',
            'lecture_students_per_group.min' => 'Lecture students per group must be at least 1.',
            'lab_session_hours.in' => 'Lab session hours must be 1 or 2.',
            'lecture_session_hours.in' => 'Lecture session hours must be 1 or 2.',
            'lab_time_slots.*.day.in' => 'Slot day must be a valid scheduling day.',
            'lecture_time_slots.*.day.in' => 'Slot day must be a valid scheduling day.',
            'lab_time_slots.*.start_time.date_format' => 'Slot times must be in HH:MM format.',
            'lecture_time_slots.*.start_time.date_format' => 'Slot times must be in HH:MM format.',
            'lab_time_slots.*.end_time.date_format' => 'Slot times must be in HH:MM format.',
            'lecture_time_slots.*.end_time.date_format' => 'Slot times must be in HH:MM format.',
            'lab_time_slots.*.end_time.after' => 'Slot end time must be after its start time.',
            'lecture_time_slots.*.end_time.after' => 'Slot end time must be after its start time.',
            'staff.*.staff_id.exists' => 'One or more assigned staff members do not exist.',
            'staff.*.role.in' => 'Staff role must be "lecturer" or "ta".',
            'staff.*.num_of_groups.min' => 'Groups per staff member must be at least 1.',
            'eligible_lab_ids.*.exists' => 'One or more eligible labs do not exist.',
        ];
    }

    protected function failedValidation(ValidatorContract $validator)
    {
        throw new HttpResponseException(response()->json([
            'status' => false,
            'message' => 'Validation Error',
            'errors' => $validator->errors(),
        ], 422));
    }
}
