<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PipelineStep;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BulkCompanyActionRequest extends FormRequest
{
    public const ACTIONS = ['contacted', 'not_interested', 'suppress_domain', 'retry'];

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
            'action' => ['required', Rule::in(self::ACTIONS)],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
            'step' => ['nullable', Rule::enum(PipelineStep::class)->except([PipelineStep::Done])],
        ];
    }
}
