<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Gate for every schedule entry change: the generation engine owns the
 * hard/soft constraint rules, so persistence refuses any change the engine
 * did not clear (fail closed).
 */
class EngineChangeValidator
{
    /**
     * Validate a hypothetical change against the schedule's current state.
     *
     * @param  array<string, mixed>  $change  Engine change payload
     * @return array{available: bool, ok: bool, payload: array, error: ?string}
     */
    public function validate(int $scheduleId, ?string $stateVersion, array $change): array
    {
        $result = $this->post([
            'schedule_id' => $scheduleId,
            'state_version' => $stateVersion,
            'change' => $change,
        ]);

        if (! $result['available']) {
            return $result;
        }

        $payload = $result['payload'];

        // A 503 from the engine means it could not (re)build schedule state —
        // for example a cold cache on a single-worker dev server, where its
        // callback fetches to this API would deadlock. That is "temporarily
        // unavailable" (retryable), never "conflict".
        if ($result['status'] >= 500) {
            return [
                'available' => false,
                'status' => $result['status'],
                'payload' => $payload,
                'error' => null,
            ];
        }

        // 400 from the engine means the change itself is malformed for this
        // schedule (unknown entry, off-grid slot, unknown room).
        if ($result['status'] === 400) {
            return [
                'available' => true,
                'ok' => false,
                'payload' => $payload,
                'error' => $payload['detail'] ?? 'The change is not valid for this schedule.',
            ];
        }

        return [
            'available' => true,
            'ok' => (bool) ($payload['ok'] ?? false),
            'payload' => $payload,
            'error' => null,
        ];
    }

    /**
     * Commit an already-validated change to the engine's cached state after
     * the database write. Best effort: if it fails, the engine cache heals
     * itself via a version-mismatch reload on the next validation.
     */
    public function apply(int $scheduleId, ?string $stateVersion, array $change, string $newVersion): bool
    {
        $result = $this->post([
            'schedule_id' => $scheduleId,
            'state_version' => $stateVersion,
            'new_version' => $newVersion,
            'apply' => true,
            'change' => $change,
        ]);

        return $result['available'] && (bool) ($result['payload']['applied'] ?? false);
    }

    /**
     * @return array{available: bool, status: int, payload: array}
     */
    private function post(array $body): array
    {
        $url = rtrim((string) config('services.engine.url'), '/').'/validate-change';

        try {
            $response = Http::timeout((int) config('services.engine.timeout'))
                ->acceptJson()
                ->post($url, $body);
        } catch (ConnectionException $exception) {
            report($exception);

            return ['available' => false, 'status' => 0, 'payload' => []];
        }

        return [
            'available' => true,
            'status' => $response->status(),
            'payload' => $response->json() ?? [],
        ];
    }
}
