<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduleSetting extends Model
{
    use HasFactory;

    public const RESERVED_PERIOD_LABEL_AR = 'أعمال جودة ومجالس';

    protected $fillable = [
        'reserved_day',
        'reserved_start_time',
        'reserved_end_time',
    ];

    public static function current(): self
    {
        return self::firstOrCreate(
            ['id' => 1],
            [
                'reserved_day' => 'tuesday',
                'reserved_start_time' => '13:00:00',
                'reserved_end_time' => '15:00:00',
            ]
        );
    }

    public function reservedPeriod(): ?array
    {
        if (! $this->reserved_day || ! $this->reserved_start_time || ! $this->reserved_end_time) {
            return null;
        }

        return [
            'day' => $this->reserved_day,
            'start_time' => substr((string) $this->reserved_start_time, 0, 5),
            'end_time' => substr((string) $this->reserved_end_time, 0, 5),
            'label_ar' => self::RESERVED_PERIOD_LABEL_AR,
        ];
    }
}
