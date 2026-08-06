<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FreshserviceDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('dashboards.view') ?? false;
    }

    public function rules(): array
    {
        return [
            'data_source_id' => ['required', 'integer', 'exists:data_sources,id'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }
}
