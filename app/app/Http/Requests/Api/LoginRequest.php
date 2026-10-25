<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Domain\Auth\DeviceInfo;
use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:256'],
            'otp' => ['nullable', 'string', 'max:32'],
            'device_uid' => ['required', 'string', 'min:16', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'device_name' => ['nullable', 'string', 'max:120'],
            // The phone's position, for a mill that allows sign-in only inside its premises.
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'platform' => ['required', 'string', Rule::in(array_diff(Device::PLATFORMS, ['web']))],
        ];
    }

    public function device(): DeviceInfo
    {
        return new DeviceInfo($this->string('device_uid')->toString(), $this->string('platform')->toString(), $this->input('device_name'));
    }
}
