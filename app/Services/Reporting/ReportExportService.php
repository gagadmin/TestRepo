<?php

namespace App\Services\Reporting;

use App\Models\Report;
use App\Models\ReportSnapshot;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportExportService
{
    public function xlsx(Report $report, ReportSnapshot $snapshot, array $rows): string
    {
        return $this->measured(
            'xlsx',
            $report,
            $rows,
            fn (): string => $this->buildXlsx($report, $snapshot, $rows),
        );
    }

    public function pdf(Report $report, ReportSnapshot $snapshot, array $rows): string
    {
        return $this->measured(
            'pdf',
            $report,
            $rows,
            fn (): string => $this->buildPdf($report, $snapshot, $rows),
        );
    }

    /**
     * Time an export and report the cost.
     *
     * Serialising a snapshot into a spreadsheet or a PDF is the memory- and
     * CPU-heavy half of producing a report — retrieval is already timed in
     * `integration_runs` — and it grows with row and column count. Measuring it
     * is what turns "the export is slow" into a size the figures can be checked
     * against. Only shape is recorded: never a cell value, and never the
     * document itself.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(): string  $build
     */
    private function measured(string $format, Report $report, array $rows, callable $build): string
    {
        $startedAt = hrtime(true);
        $output = $build();

        Log::info('Report export generated.', [
            'report_id' => $report->id,
            'format' => $format,
            'rows' => count($rows),
            'columns' => count($report->definition['columns'] ?? []),
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'bytes' => strlen($output),
        ]);

        return $output;
    }

    private function buildXlsx(Report $report, ReportSnapshot $snapshot, array $rows): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(Str::limit(preg_replace('/[\\\\\\/?*\\[\\]:]/', '', $report->name), 31, ''));
        $columns = $report->definition['columns'] ?? [];
        $lastColumn = $this->columnLetter(max(1, count($columns)));

        $sheet->setCellValue('A1', $report->name);
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->setCellValue('A2', 'Generated '.optional($snapshot->generated_at)->format('d M Y, H:i T'));
        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18)->getColor()->setRGB('16324F');
        $sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('667085');

        foreach ($columns as $index => $column) {
            $cell = $this->columnLetter($index + 1).'4';
            $sheet->setCellValue($cell, $column['label']);
        }

        $sheet->getStyle("A4:{$lastColumn}4")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '146C94']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);

        foreach ($rows as $rowIndex => $row) {
            foreach ($columns as $columnIndex => $column) {
                $coordinate = $this->columnLetter($columnIndex + 1).($rowIndex + 5);
                $value = $row[$column['key']] ?? null;

                if (in_array($column['type'] ?? 'text', ['number', 'currency', 'percentage'], true) && is_numeric($value)) {
                    $sheet->setCellValue($coordinate, (float) $value);
                } else {
                    $sheet->setCellValueExplicit($coordinate, (string) ($value ?? ''), DataType::TYPE_STRING);
                }
            }
        }

        $lastRow = max(4, count($rows) + 4);

        foreach ($columns as $index => $column) {
            $format = match ($column['type'] ?? 'text') {
                'currency' => '"AED" #,##0.00',
                'number' => '#,##0.00',
                'percentage' => '0.0\\%',
                default => null,
            };

            if ($format && $lastRow >= 5) {
                $letter = $this->columnLetter($index + 1);
                $sheet->getStyle("{$letter}5:{$letter}{$lastRow}")->getNumberFormat()->setFormatCode($format);
            }
        }

        $sheet->getStyle("A4:{$lastColumn}{$lastRow}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB('D0D5DD');
        $sheet->setAutoFilter("A4:{$lastColumn}{$lastRow}");
        $sheet->freezePane('A5');

        foreach (range(1, max(1, count($columns))) as $index) {
            $sheet->getColumnDimension($this->columnLetter($index))->setAutoSize(true);
        }

        $path = tempnam(sys_get_temp_dir(), 'ask-gaholding-xlsx-');
        (new Xlsx($book))->save($path);
        $contents = file_get_contents($path);
        unlink($path);
        $book->disconnectWorksheets();

        return $contents;
    }

    private function buildPdf(Report $report, ReportSnapshot $snapshot, array $rows): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('exports.report', compact('report', 'snapshot', 'rows'))->render());
        $dompdf->setPaper('A4', count($report->definition['columns'] ?? []) > 6 ? 'landscape' : 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function columnLetter(int $number): string
    {
        $letter = '';

        while ($number > 0) {
            $number--;
            $letter = chr(65 + ($number % 26)).$letter;
            $number = intdiv($number, 26);
        }

        return $letter ?: 'A';
    }
}
