<?php

namespace App\Services\Reporting;

use App\Models\Report;
use App\Models\ReportSnapshot;
use Dompdf\Dompdf;
use Dompdf\Options;
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

    public function pdf(Report $report, ReportSnapshot $snapshot, array $rows): string
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
