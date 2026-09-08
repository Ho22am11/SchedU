<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateScheduleSettingsRequest;
use App\Models\ScheduleSetting;
use App\Traits\ApiResponseTrait;

class ScheduleSettingsController extends Controller
{
    use ApiResponseTrait;

    public function show()
    {
        return $this->ApiResponse(
            ['reserved_period' => ScheduleSetting::current()->reservedPeriod()],
            'Schedule settings retrieved successfully',
            200
        );
    }

    public function update(UpdateScheduleSettingsRequest $request)
    {
        $period = $request->validated('reserved_period');

        $settings = ScheduleSetting::current();
        $settings->update([
            'reserved_day' => $period['day'] ?? null,
            'reserved_start_time' => $period['start_time'] ?? null,
            'reserved_end_time' => $period['end_time'] ?? null,
        ]);

        return $this->ApiResponse(
            ['reserved_period' => $settings->fresh()->reservedPeriod()],
            'Schedule settings updated successfully',
            200
        );
    }
}
