<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchConsolePreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('integrations.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'dimension' => ['nullable', Rule::in(['query', 'page', 'country', 'device', 'date'])],
            'limit' => ['nullable', 'integer', 'between:1,200'],
        ];
    }
}
