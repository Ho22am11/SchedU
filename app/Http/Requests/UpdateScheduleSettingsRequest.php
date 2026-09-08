<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateScheduleSettingsRequest extends FormRequest
{
    private const START_TIMES = ['09:00', '11:00', '13:00', '15:00', '17:00'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reserved_period' => ['nullable', 'array'],
            'reserved_period.day' => [
                'required_with:reserved_period',
                'string',
                Rule::in(['sunday', 'monday', 'tuesday', 'wednesday', 'thursday']),
            ],
            'reserved_period.start_time' => ['required_with:reserved_period', 'date_format:H:i'],
            'reserved_period.end_time' => ['required_with:reserved_period', 'date_format:H:i'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $period = $this->input('reserved_period', []);
            if ($period === null) {
                return;
            }
            $startTime = $period['start_time'] ?? null;
            $endTime = $period['end_time'] ?? null;

            if (! is_string($startTime) || ! is_string($endTime)) {
                return;
            }

            if (! in_array($startTime, self::START_TIMES, true)) {
                $validator->errors()->add(
                    'reserved_period.start_time',
                    'The reserved period must start on the two-hour timetable grid.'
                );
            }

            if ($endTime <= $startTime) {
                $validator->errors()->add(
                    'reserved_period.end_time',
                    'The reserved period end time must be after the start time.'
                );

                return;
            }

            $expectedEndTime = date('H:i', strtotime($startTime.' +2 hours'));
            if ($endTime !== $expectedEndTime || $endTime > '19:00') {
                $validator->errors()->add(
                    'reserved_period.end_time',
                    'The reserved period must be exactly two hours and end by 19:00.'
                );
            }
        });
    }
}
