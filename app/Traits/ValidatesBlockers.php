<?php

namespace App\Traits;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

trait ValidatesBlockers
{
    /**
     * Blockers must sit on the same two-hour grid as every other timing
     * surface in the app: a working day, a grid start, end = start + 2h
     * (so end ≤ 19:00 by construction), a non-empty label, and no exact
     * duplicate for the same staff member within the validated set. Rows
     * may omit lecturer_id when the set is already scoped to one staff
     * member (staff-page create/update). Partial overlaps stay allowed —
     * the engine unions blocked time. Throws a 422 ValidationException
     * with per-field messages on the first bad row.
     */
    protected function validateBlockers(array $blockers, string $field): void
    {
        $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday'];
        $starts = ['09:00', '11:00', '13:00', '15:00', '17:00'];

        $seen = [];

        foreach (array_values($blockers) as $index => $blocker) {
            $base = $field.'.'.$index;
            $errors = [];

            $day = strtolower(trim((string) ($blocker['day'] ?? '')));
            if (! in_array($day, $days, true)) {
                $errors[$base.'.day'] = ['The blocker day must be one of: '.implode(', ', $days).'.'];
            }

            $start = $blocker['startTime'] ?? null;
            $end = $blocker['endTime'] ?? null;

            if (! is_string($start) || ! preg_match('/^\d{2}:\d{2}$/', $start)) {
                $errors[$base.'.startTime'] = ['The blocker start time must be in HH:MM format.'];
            }

            if (! is_string($end) || ! preg_match('/^\d{2}:\d{2}$/', $end)) {
                $errors[$base.'.endTime'] = ['The blocker end time must be in HH:MM format.'];
            }

            if ($errors === []) {
                if (! in_array($start, $starts, true)) {
                    $errors[$base.'.startTime'] = ['The blocker start must be one of: '.implode(', ', $starts).'.'];
                }

                $expectedEnd = Carbon::createFromFormat('H:i', $start)->addHours(2)->format('H:i');
                if ($end !== $expectedEnd) {
                    $errors[$base.'.endTime'] = ['The blocker must span exactly two hours from its start.'];
                }
            }

            $label = $blocker['label'] ?? null;
            if (! is_string($label) || trim($label) === '') {
                $errors[$base.'.label'] = ['The blocker label is required.'];
            } elseif (mb_strlen($label) > 191) {
                $errors[$base.'.label'] = ['The blocker label may not exceed 191 characters.'];
            }

            $owner = (string) ($blocker['lecturer_id'] ?? '');
            $duplicateKey = $owner.'|'.$day.'|'.$start.'|'.$end;
            if ($errors === [] && isset($seen[$duplicateKey])) {
                $errors[$base] = ['This blocker duplicates another blocker in the same set.'];
            }
            $seen[$duplicateKey] = true;

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }
    }
}
