<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class RefreshRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'refresh_token' => ['required', 'string', 'starts_with:rmr_', 'max:128'],
        ];
    }

    public function deviceUid(): string
    {
        return (string) $this->header('X-Device-Id', '');
    }
}
