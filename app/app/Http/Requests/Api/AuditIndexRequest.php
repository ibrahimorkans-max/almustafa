<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class AuditIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('audit.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['nullable', 'string', 'max:100'],
            'subject_type' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'string', 'max:64'],
            'actor_login_account_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
