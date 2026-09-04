<?php

namespace App\Http\Requests;

use App\Models\DataSource;
use App\Models\Report;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('reports.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in([
                'sales', 'crm_pipeline', 'website_analytics', 'asset_inventory',
                'procurement_spend', 'executive_kpi', 'financial_overview',
                'itsm_ticket_summary', 'custom',
            ])],
            'description' => ['nullable', 'string', 'max:1000'],
            'visibility' => ['required', Rule::in(['private', 'department', 'enterprise'])],
            'definition' => ['required', 'array'],
            'definition.source_id' => ['nullable', 'integer', 'exists:data_sources,id'],
            'definition.department' => ['nullable', 'string', 'max:120'],
            'definition.columns' => ['required', 'array', 'min:1', 'max:40'],
            'definition.columns.*.key' => ['required', 'string', 'max:120'],
            'definition.columns.*.label' => ['required', 'string', 'max:120'],
            'definition.columns.*.type' => ['nullable', Rule::in(['text', 'number', 'currency', 'percentage', 'date'])],
            'definition.chart' => ['nullable', 'array'],
            'definition.chart.type' => ['nullable', Rule::in(['bar', 'line', 'area', 'donut'])],
            'definition.chart.category_key' => ['nullable', 'string', 'max:120'],
            'definition.chart.value_key' => ['nullable', 'string', 'max:120'],
            'definition.chart.title' => ['nullable', 'string', 'max:160'],
            'definition.filters' => ['nullable', 'array', 'max:12'],
            'definition.search_console_dimension' => [
                'nullable',
                Rule::in(['query', 'page', 'country', 'device', 'date']),
            ],
            'definition.allowed_roles' => ['nullable', 'array', 'max:5'],
            'definition.allowed_roles.*' => ['string', Rule::in($this->grantableRoles())],
            'definition.allowed_departments' => ['nullable', 'array', 'max:30'],
            'definition.allowed_departments.*' => ['string', 'max:120'],
            'definition.columns.*.mask' => ['nullable', Rule::in(['email', 'phone', 'last4', 'redact'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        if (! $this->publishesToADepartment()) {
            return [];
        }

        return [
            'definition.allowed_roles.*.in' => 'A department report may only grant the '
                .implode(' or ', Report::CROSS_CUTTING_ROLES)
                .' role. Every other role reaches the report through the departments configured on the account.',
        ];
    }

    /**
     * Roles this report may name in its `allowed_roles` grant.
     *
     * Visibility decides. A role grant is an alternative to the departmental
     * check, so leaving the list unrestricted on a department-scoped report
     * would let any author publish departmental data to a whole role and
     * recreate, one record at a time, the access-profile bypass that
     * `2026_09_03_000200_restrict_department_dashboard_role_grants` closed for
     * the seeded records. Enterprise and private reports are unaffected: they
     * carry no departmental promise to break.
     *
     * @return list<string>
     */
    private function grantableRoles(): array
    {
        return $this->publishesToADepartment()
            ? Report::CROSS_CUTTING_ROLES
            : Report::GRANTABLE_ROLES;
    }

    private function publishesToADepartment(): bool
    {
        return $this->input('visibility') === 'department';
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $sourceId = $this->input('definition.source_id');

                if (! $sourceId || $validator->errors()->has('definition.source_id')) {
                    return;
                }

                $source = DataSource::query()->find($sourceId);

                if (! $source || ! $source->isAccessibleBy($this->user())) {
                    $validator->errors()->add('definition.source_id', 'The selected data source is not authorized for your account.');

                    return;
                }

                $allowedTypes = config("reporting.source_types.{$this->input('type')}", []);

                if (! in_array($source->type, $allowedTypes, true)) {
                    $validator->errors()->add('definition.source_id', 'The selected data source is not compatible with this report type.');
                }
            },
        ];
    }
}
