<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportRequest;
use App\Models\AuditLog;
use App\Models\DataSource;
use App\Models\Report;
use App\Models\ReportSnapshot;
use App\Services\Reporting\ReportDataService;
use App\Services\Reporting\ReportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $reports = Report::query()
            ->visibleTo($request->user())
            ->with(['owner:id,name', 'latestSnapshot'])
            ->latest()
            ->get()
            ->map(fn (Report $report) => $this->serialize($report));

        return response()->json([
            'data' => $reports,
            'sources' => DataSource::query()
                ->where('status', 'connected')
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'owner_id', 'settings'])
                ->filter(fn (DataSource $source) => $source->isAccessibleBy($request->user()))
                ->map(fn (DataSource $source) => $source->only(['id', 'name', 'type']))
                ->values(),
            'types' => $this->reportTypes(),
        ]);
    }

    public function store(ReportRequest $request): JsonResponse
    {
        $definition = $this->governDefinition($request, $request->validated('definition'));

        $report = Report::create([
            ...$request->safe()->only(['name', 'type', 'description', 'visibility']),
            'user_id' => $request->user()->id,
            'definition' => $definition,
        ]);

        return response()->json(['data' => $this->serialize($report->load('owner'))], 201);
    }

    public function update(ReportRequest $request, int $report): JsonResponse
    {
        $model = $this->editableReport($request, $report);
        $definition = $this->governDefinition($request, $request->validated('definition'));
        $invalidatesSnapshot = data_get($model->definition, 'source_id') !== data_get($definition, 'source_id')
            || data_get($model->definition, 'columns') !== data_get($definition, 'columns')
            || data_get($model->definition, 'search_console_dimension') !== data_get($definition, 'search_console_dimension');

        $model->update([
            ...$request->safe()->only(['name', 'type', 'description', 'visibility']),
            'definition' => $definition,
        ]);

        if ($invalidatesSnapshot) {
            $model->snapshots()->delete();
            $model->update(['last_generated_at' => null]);
        }

        return response()->json(['data' => $this->serialize($model->fresh(['owner', 'latestSnapshot']))]);
    }

    public function show(Request $request, int $report, ReportDataService $data): JsonResponse
    {
        $model = $this->visibleReport($request, $report);
        $snapshot = $model->latestSnapshot;
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'department' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'data' => [
                ...$this->serialize($model),
                'rows' => $data->filteredRows($snapshot, $filters),
                'summary' => $snapshot?->summary ?? [],
                'citations' => $snapshot?->citations ?? [],
            ],
        ]);
    }

    public function generate(Request $request, int $report, ReportDataService $data): JsonResponse
    {
        $model = $this->editableReport($request, $report);
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'department' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $snapshot = $data->generate($model, $request->user(), $filters);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Report refreshed from its connected source.',
            'data' => [
                ...$this->serialize($model->fresh(['owner', 'latestSnapshot'])),
                'rows' => $snapshot->data,
                'summary' => $snapshot->summary,
                'citations' => $snapshot->citations,
            ],
        ]);
    }

    public function export(
        Request $request,
        int $report,
        string $format,
        ReportDataService $data,
        ReportExportService $exporter
    ): Response {
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 404);
        $model = $this->visibleReport($request, $report);
        $snapshot = $model->latestSnapshot;
        abort_unless($snapshot instanceof ReportSnapshot, 422, 'Generate the report before exporting it.');

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'department' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:120'],
        ]);
        $rows = $data->filteredRows($snapshot, $filters);
        $contents = $format === 'xlsx'
            ? $exporter->xlsx($model, $snapshot, $rows)
            : $exporter->pdf($model, $snapshot, $rows);
        $mime = $format === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'application/pdf';

        AuditLog::create([
            'user_id' => $request->user()->id,
            'event' => 'report.exported',
            'auditable_type' => Report::class,
            'auditable_id' => (string) $model->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                'format' => $format,
                'row_count' => count($rows),
                'snapshot_id' => $snapshot->id,
                'filters' => $filters,
            ],
        ]);

        return response($contents, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="'.Str::slug($model->name).'.'.$format.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function visibleReport(Request $request, int $report): Report
    {
        return Report::query()
            ->visibleTo($request->user())
            ->with(['owner:id,name', 'latestSnapshot'])
            ->findOrFail($report);
    }

    private function editableReport(Request $request, int $report): Report
    {
        $model = $this->visibleReport($request, $report);
        $isAdmin = $request->user()->roles()->where('name', 'administrator')->exists();
        abort_unless($model->user_id === $request->user()->id || $isAdmin, 403);

        return $model;
    }

    private function serialize(Report $report): array
    {
        return [
            'id' => $report->id,
            'name' => $report->name,
            'type' => $report->type,
            'type_label' => collect($this->reportTypes())->firstWhere('value', $report->type)['label'] ?? $report->type,
            'description' => $report->description,
            'visibility' => $report->visibility,
            'definition' => $report->definition,
            'owner' => $report->owner?->only(['id', 'name']),
            'last_generated_at' => $report->last_generated_at,
            'latest_snapshot' => $report->latestSnapshot ? [
                'id' => $report->latestSnapshot->id,
                'row_count' => $report->latestSnapshot->row_count,
                'generated_at' => $report->latestSnapshot->generated_at,
            ] : null,
        ];
    }

    private function reportTypes(): array
    {
        return [
            ['value' => 'sales', 'label' => 'Sales performance'],
            ['value' => 'crm_pipeline', 'label' => 'CRM pipeline'],
            ['value' => 'website_analytics', 'label' => 'Website analytics'],
            ['value' => 'asset_inventory', 'label' => 'Asset inventory'],
            ['value' => 'procurement_spend', 'label' => 'Procurement spend'],
            ['value' => 'executive_kpi', 'label' => 'Executive KPI'],
            ['value' => 'financial_overview', 'label' => 'Financial overview'],
            ['value' => 'itsm_ticket_summary', 'label' => 'Freshservice ITSM summary'],
            ['value' => 'custom', 'label' => 'Custom report'],
        ];
    }

    private function governDefinition(Request $request, array $definition): array
    {
        $visibility = $request->string('visibility')->toString();

        if ($visibility === 'enterprise') {
            abort_unless($request->user()->hasPermission('reports.publish'), 403);
            $definition['allowed_roles'] = array_values(array_unique([
                ...($definition['allowed_roles'] ?? ['executive']),
                'administrator',
            ]));
            unset($definition['department'], $definition['allowed_departments']);
        }

        if ($visibility === 'department') {
            /*
             * Honour an explicitly chosen department, but only one the author is
             * permitted to view. A multi-department author was otherwise forced
             * to publish into their primary department label (KI-017), while
             * accepting the submitted value unchecked would let anyone publish
             * a report into a department they cannot see.
             */
            $author = $request->user();
            $requested = trim((string) ($definition['department'] ?? ''));
            $department = $requested !== '' && $author->canViewDepartment($requested)
                ? $requested
                : ($author->accessibleDepartments()[0] ?? null);

            abort_unless($department, 422, 'A department is required for departmental visibility.');
            $definition['department'] = $department;
            $definition['allowed_departments'] = [$department];
            /*
             * A role grant is an alternative to the departmental check, so only
             * a genuinely cross-cutting role may carry one here. Admitting a
             * broad business role - `executive` was admitted until WEB-671 -
             * publishes departmental data to every holder of that role and
             * bypasses the access profile outright. The Form Request rejects
             * such a grant with a message the author can act on; this intersect
             * is the server-side floor for anything that reaches the model by
             * another path.
             */
            $definition['allowed_roles'] = array_values(array_intersect(
                $definition['allowed_roles'] ?? [],
                Report::CROSS_CUTTING_ROLES
            ));
        }

        return $definition;
    }
}
