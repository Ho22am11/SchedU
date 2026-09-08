<?php

namespace App\Services;

use App\Models\Academic;
use App\Models\Lecturer;
use App\Models\ScheduleEntry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DeletionGuard
{
    /**
     * Labels used in the human-readable 409 message the frontend toasts.
     */
    private const LABELS = [
        'hall' => 'hall',
        'lap' => 'lab',
        'lecturer' => 'lecturer',
        'department' => 'department',
    ];

    /**
     * Run the delete only when nothing references the rows (or the caller
     * explicitly forced it). Returns a 409 JsonResponse describing the usage
     * when blocked, null when the delete ran. Departments never honor force:
     * lecturers and academics RESTRICT on their department_id.
     */
    public function guard(string $type, array $ids, bool $force, Closure $delete): ?JsonResponse
    {
        if (! isset(self::LABELS[$type])) {
            throw new InvalidArgumentException("Unknown deletion guard type [{$type}].");
        }

        $references = $this->references($type, $ids);

        if ($references !== [] && ! ($force && $type !== 'department')) {
            return response()->json([
                'status' => false,
                'message' => $this->blockMessage($type, $references, $force),
                'blocked_ids' => array_keys($references),
                'errors' => ['references' => $references],
            ], 409);
        }

        DB::transaction($delete);

        return null;
    }

    /**
     * Referenced id => usage count, containing only the ids that are in use.
     *
     * @return array<int, int>
     */
    private function references(string $type, array $ids): array
    {
        $counts = match ($type) {
            'hall' => $this->entryCounts('hall_id', $ids),
            'lap' => $this->entryCounts('lap_id', $ids),
            'lecturer' => $this->entryCounts('lecturer_id', $ids),
            'department' => $this->departmentCounts($ids),
        };

        ksort($counts);

        return $counts;
    }

    /**
     * @return array<int, int>
     */
    private function entryCounts(string $column, array $ids): array
    {
        return ScheduleEntry::whereIn($column, $ids)
            ->selectRaw("{$column}, count(*) as usage_count")
            ->groupBy($column)
            ->pluck('usage_count', $column)
            ->all();
    }

    /**
     * Departments are referenced by lecturers and academics through RESTRICT
     * foreign keys, and historically by schedule entries through the
     * department_ids JSON column. All three must be clear (or reassigned)
     * before a department can go.
     *
     * @return array<int, int>
     */
    private function departmentCounts(array $ids): array
    {
        $counts = [];

        foreach ([
            Lecturer::class,
            Academic::class,
        ] as $model) {
            $model::whereIn('department_id', $ids)
                ->selectRaw('department_id, count(*) as usage_count')
                ->groupBy('department_id')
                ->pluck('usage_count', 'department_id')
                ->each(function (int $count, int $id) use (&$counts) {
                    $counts[$id] = ($counts[$id] ?? 0) + $count;
                });
        }

        // The JSON column cannot be grouped, so count per candidate id.
        foreach ($ids as $id) {
            $sessions = ScheduleEntry::whereJsonContains('department_ids', $id)->count();
            if ($sessions > 0) {
                $counts[$id] = ($counts[$id] ?? 0) + $sessions;
            }
        }

        return $counts;
    }

    /**
     * Single-sentence explanation; the frontend interceptor toasts exactly
     * this field.
     *
     * @param  array<int, int>  $references
     */
    private function blockMessage(string $type, array $references, bool $forceRequested): string
    {
        $label = self::LABELS[$type];
        $total = array_sum($references);

        if ($type === 'department') {
            $suffix = $forceRequested ? ' Force is not available for departments.' : '';

            return "Cannot delete this department: {$total} lecturer(s), academic list(s) or schedule session(s) still reference it. Reassign them first.{$suffix}";
        }

        return "Cannot delete this {$label}: it is used by {$total} schedule session(s). Delete anyway with force=1 to keep those sessions under the recorded {$label} name, or reassign the sessions first.";
    }
}
