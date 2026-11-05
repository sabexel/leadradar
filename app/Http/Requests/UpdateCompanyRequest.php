<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OutreachStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'outreach_status' => ['sometimes', Rule::enum(OutreachStatus::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }
}
