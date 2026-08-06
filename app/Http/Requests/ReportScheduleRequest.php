<?php

namespace App\Http\Requests;

use Cron\CronExpression;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReportScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('reports.schedule') ?? false;
    }

    public function rules(): array
    {
        return [
            'report_id' => ['required', 'integer', 'exists:reports,id'],
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'custom'])],
            'cron_expression' => ['required', 'string', 'max:100'],
            'timezone' => ['required', 'timezone:all'],
            'format' => ['required', Rule::in(['pdf', 'xlsx'])],
            'filters' => ['nullable', 'array'],
            'filters.date_from' => ['nullable', 'date'],
            'filters.date_to' => ['nullable', 'date', 'after_or_equal:filters.date_from'],
            'filters.department' => ['nullable', 'string', 'max:120'],
            'filters.region' => ['nullable', 'string', 'max:120'],
            'filters.status' => ['nullable', 'string', 'max:120'],
            'delivery_channels' => ['required', 'array', 'min:1', 'max:2'],
            'delivery_channels.*' => ['required', Rule::in(['email', 'teams'])],
            'recipients' => ['nullable', 'array', 'max:50'],
            'recipients.*' => ['required', 'email:rfc', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! CronExpression::isValidExpression((string) $this->input('cron_expression'))) {
                    $validator->errors()->add('cron_expression', 'Enter a valid five-part cron expression.');
                }

                if (in_array('email', $this->input('delivery_channels', []), true) && empty($this->input('recipients'))) {
                    $validator->errors()->add('recipients', 'At least one email recipient is required.');
                }

                if (in_array('teams', $this->input('delivery_channels', []), true)
                    && blank(config('services.teams.webhook_url'))) {
                    $validator->errors()->add('delivery_channels', 'Microsoft Teams delivery is not configured.');
                }
            },
        ];
    }
}
