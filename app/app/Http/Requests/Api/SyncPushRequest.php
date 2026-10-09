<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** Envelope validation only; payloads are validated per event type by their handler. */
final class SyncPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:'.(int) config('ricemill.sync.max_push_events')],
            'events.*.event_id' => ['required', 'uuid', 'distinct'],
            'events.*.client_seq' => ['required', 'integer', 'min:1', 'distinct'],
            'events.*.occurred_at' => ['required', 'date'],
            'events.*.type' => ['required', 'string', 'max:64', 'regex:/^[a-z_]+\.[a-z_]+$/'],
            'events.*.record_id' => ['nullable', 'uuid'],
            'events.*.base_version' => ['nullable', 'integer', 'min:0'],
            'events.*.payload' => ['present', 'array'],
            // The device's clock at upload time (boot / app-session id, monotonic ms, wall time), used to date
            // offline attendance punches independently of the phone's settable clock.
            'device_clock' => ['nullable', 'array'],
            'device_clock.boot_id' => ['nullable', 'string', 'max:64'],
            'device_clock.monotonic_ms' => ['nullable', 'integer', 'min:0'],
            'device_clock.wall_time' => ['nullable', 'date'],
        ];
    }
}
