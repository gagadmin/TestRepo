<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsInsight;
use App\Models\Report;
use App\Models\ReportSnapshot;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AdvancedAnalyticsService
{
    public function analyze(Report $report, ReportSnapshot $snapshot, User $user): Collection
    {
        $rows = collect($snapshot->data);
        $metrics = collect($report->definition['columns'] ?? [])
            ->filter(fn (array $column) => in_array($column['type'] ?? 'text', ['number', 'currency', 'percentage'], true))
            ->mapWithKeys(fn (array $column) => [$column['key'] => $column['label'] ?? Str::headline($column['key'])]);

        if ($rows->count() < 3 || $metrics->isEmpty()) {
            throw new RuntimeException('At least three rows and one numeric metric are required for advanced analytics.');
        }

        $batchId = (string) Str::uuid();
        $generatedAt = now();
        $attributes = [];

        foreach ($metrics as $key => $label) {
            $series = $rows
                ->pluck($key)
                ->filter(fn ($value) => is_numeric($value))
                ->map(fn ($value) => (float) $value)
                ->values()
                ->all();

            if (count($series) < 3) {
                continue;
            }

            $trend = $this->trend($series);
            $attributes[] = $this->insight(
                $batchId,
                $report,
                $snapshot,
                $user,
                'trend',
                $trend['direction'] === 'down' ? 'warning' : 'info',
                $key,
                "{$label} trend",
                $this->trendNarrative($label, $trend),
                $trend,
                $generatedAt
            );

            $anomalies = $this->anomalies($series);

            foreach (array_slice($anomalies, 0, 3) as $anomaly) {
                $attributes[] = $this->insight(
                    $batchId,
                    $report,
                    $snapshot,
                    $user,
                    'anomaly',
                    'warning',
                    $key,
                    "Unusual {$label} value",
                    "{$label} at row ".($anomaly['index'] + 1).' differs materially from its recent pattern.',
                    $anomaly,
                    $generatedAt
                );
            }

            $forecast = $this->forecast($series, 3);
            $attributes[] = $this->insight(
                $batchId,
                $report,
                $snapshot,
                $user,
                'forecast',
                'info',
                $key,
                "{$label} forecast",
                "A three-period statistical projection was generated for {$label}.",
                ['horizon' => 3, 'values' => $forecast, 'method' => 'linear_regression'],
                $generatedAt
            );

            if ($anomalies !== [] || ($trend['change_percent'] !== null && $trend['change_percent'] <= -5)) {
                $reason = $anomalies !== []
                    ? 'Review the source records behind the detected outliers before making operational decisions.'
                    : "Investigate the drivers behind the {$label} decline and validate the trend against current targets.";
                $attributes[] = $this->insight(
                    $batchId,
                    $report,
                    $snapshot,
                    $user,
                    'recommendation',
                    'action',
                    $key,
                    "Review {$label}",
                    $reason,
                    ['basis' => $anomalies !== [] ? 'anomaly' : 'declining_trend'],
                    $generatedAt
                );
            }
        }

        $attributes = array_slice($attributes, 0, (int) config('reporting.max_analytics_insights', 50));

        if ($attributes === []) {
            throw new RuntimeException('The snapshot does not contain enough numeric values for analysis.');
        }

        return DB::transaction(function () use ($report, $snapshot, $attributes) {
            AnalyticsInsight::query()
                ->where('report_id', $report->id)
                ->where('report_snapshot_id', $snapshot->id)
                ->delete();

            return collect($attributes)->map(fn (array $item) => AnalyticsInsight::create($item));
        });
    }

    private function trend(array $series): array
    {
        $slope = $this->slope($series);
        $first = $series[0];
        $last = $series[array_key_last($series)];
        $change = $first == 0.0 ? null : (($last - $first) / abs($first)) * 100;
        $tolerance = max(abs(array_sum($series) / count($series)) * 0.005, 0.00001);
        $direction = abs($slope) <= $tolerance ? 'stable' : ($slope > 0 ? 'up' : 'down');

        return [
            'direction' => $direction,
            'slope' => round($slope, 4),
            'change_percent' => $change === null ? null : round($change, 2),
            'first_value' => $first,
            'last_value' => $last,
            'points' => count($series),
        ];
    }

    private function anomalies(array $series): array
    {
        if (count($series) < 4) {
            return [];
        }

        $median = $this->median($series);
        $deviations = array_map(fn (float $value) => abs($value - $median), $series);
        $mad = $this->median($deviations);

        if ($mad == 0.0) {
            return [];
        }

        $threshold = (float) config('reporting.analytics_anomaly_threshold', 3.5);
        $anomalies = [];

        foreach ($series as $index => $value) {
            $score = 0.6745 * ($value - $median) / $mad;

            if (abs($score) >= $threshold) {
                $anomalies[] = [
                    'index' => $index,
                    'value' => $value,
                    'median' => $median,
                    'score' => round($score, 2),
                ];
            }
        }

        usort($anomalies, fn (array $left, array $right) => abs($right['score']) <=> abs($left['score']));

        return $anomalies;
    }

    private function forecast(array $series, int $horizon): array
    {
        $slope = $this->slope($series);
        $averageX = (count($series) - 1) / 2;
        $averageY = array_sum($series) / count($series);
        $intercept = $averageY - ($slope * $averageX);
        $nonNegative = min($series) >= 0;
        $forecast = [];

        for ($index = count($series); $index < count($series) + $horizon; $index++) {
            $value = $intercept + ($slope * $index);
            $forecast[] = round($nonNegative ? max(0, $value) : $value, 2);
        }

        return $forecast;
    }

    private function slope(array $series): float
    {
        $count = count($series);
        $averageX = ($count - 1) / 2;
        $averageY = array_sum($series) / $count;
        $numerator = 0.0;
        $denominator = 0.0;

        foreach ($series as $index => $value) {
            $numerator += ($index - $averageX) * ($value - $averageY);
            $denominator += ($index - $averageX) ** 2;
        }

        return $denominator == 0.0 ? 0.0 : $numerator / $denominator;
    }

    private function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 0
            ? ($values[$middle - 1] + $values[$middle]) / 2
            : $values[$middle];
    }

    private function trendNarrative(string $label, array $trend): string
    {
        $change = $trend['change_percent'] === null
            ? 'a non-comparable baseline'
            : abs($trend['change_percent']).'% overall change';

        return "{$label} is {$trend['direction']} across {$trend['points']} observations, with {$change}.";
    }

    private function insight(
        string $batchId,
        Report $report,
        ReportSnapshot $snapshot,
        User $user,
        string $type,
        string $severity,
        string $metricKey,
        string $title,
        string $narrative,
        array $payload,
        \DateTimeInterface $generatedAt
    ): array {
        return [
            'batch_id' => $batchId,
            'report_id' => $report->id,
            'report_snapshot_id' => $snapshot->id,
            'generated_by' => $user->id,
            'type' => $type,
            'severity' => $severity,
            'metric_key' => $metricKey,
            'title' => $title,
            'narrative' => $narrative,
            'payload' => $payload,
            'generated_at' => $generatedAt,
        ];
    }
}
