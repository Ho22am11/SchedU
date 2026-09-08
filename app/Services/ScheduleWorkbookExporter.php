<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ScheduleWorkbookExporter
{
    public function addWorksheet(
        Spreadsheet $spreadsheet,
        string $worksheetTitle,
        string $title,
        string $filterString,
        array $data,
        array $entries,
        array &$colorMap,
        array $signatureTitles = []
    ): void {
        $sheet = $spreadsheet->getSheetCount() === 1
            && $spreadsheet->getActiveSheet()->getTitle() === 'Worksheet'
            ? $spreadsheet->getActiveSheet()
            : $spreadsheet->createSheet();

        $sheet->setTitle($this->makeUniqueWorksheetTitle($spreadsheet, $worksheetTitle, $sheet));
        $sheet->setRightToLeft(true);

        $columnCount = count($data['timeSlots']) + 1;
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);

        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']],
        ]);

        $headerRow = 2;
        if ($filterString !== '') {
            $sheet->mergeCells("A2:{$lastColumn}2");
            $sheet->setCellValue('A2', $filterString);
            $sheet->getStyle('A2')->applyFromArray([
                'font' => ['bold' => true, 'size' => 12],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F4FD']],
            ]);
            $headerRow = 3;
        }

        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2EFDA']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];

        $sheet->setCellValue("A{$headerRow}", 'اليوم / الوقت');
        $sheet->getStyle("A{$headerRow}")->applyFromArray($headerStyle);

        foreach ($data['timeSlots'] as $index => $slot) {
            $column = Coordinate::stringFromColumnIndex($index + 2);
            $sheet->setCellValue("{$column}{$headerRow}", $slot);
            $sheet->getStyle("{$column}{$headerRow}")->applyFromArray($headerStyle);
        }

        $row = $headerRow + 1;
        foreach ($data['days'] as $day) {
            $sheet->setCellValue("A{$row}", $day);
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF2CC']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            foreach ($data['timeSlots'] as $index => $slot) {
                $column = Coordinate::stringFromColumnIndex($index + 2);
                $cell = "{$column}{$row}";
                $entry = $data['table'][$day][$slot] ?? '';

                if (empty($entry)) {
                    continue;
                }

                $sheet->setCellValue($cell, implode("\n", $entry['html']));
                $sheet->getStyle($cell)->getAlignment()
                    ->setWrapText(true)
                    ->setVertical(Alignment::VERTICAL_CENTER)
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);

                if (! empty($entry['is_reserved'])) {
                    $sheet->getStyle($cell)->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setRGB('E7E5E4');
                    $sheet->getStyle($cell)->getFont()->setBold(true);

                    continue;
                }

                $courseKey = $entry['course_key'] ?? '';
                if ($courseKey !== '') {
                    $sheet->getStyle($cell)->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setRGB($this->courseColor($courseKey, $colorMap));
                }
            }

            $row++;
        }

        $metadata = $this->metadata($entries);
        $metadataStartRow = $row + 2;
        $sheet->mergeCells("A{$metadataStartRow}:D{$metadataStartRow}");
        $sheet->setCellValue("A{$metadataStartRow}", 'إحصائيات الجدول');
        $sheet->getStyle("A{$metadataStartRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 14],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']],
        ]);

        $metadataHeaders = ['إجمالي الجلسات', 'إجمالي المقررات', 'إجمالي الغرف', 'إجمالي أعضاء هيئة التدريس'];
        $metadataValues = [$metadata['total_sessions'], $metadata['total_courses'], $metadata['total_rooms'], $metadata['total_staff']];
        $metadataHeaderRow = $metadataStartRow + 1;

        foreach ($metadataHeaders as $index => $header) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue("{$column}{$metadataHeaderRow}", $header);
            $sheet->setCellValue("{$column}".($metadataHeaderRow + 1), $metadataValues[$index]);
            $sheet->getStyle("{$column}{$metadataHeaderRow}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2EFDA']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $sheet->getStyle("{$column}".($metadataHeaderRow + 1))->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("{$column}".($metadataHeaderRow + 1))->getFont()->setBold(true);
            $sheet->getColumnDimension($column)->setWidth(25);
        }

        $sheet->getStyle("A{$metadataStartRow}:D".($metadataHeaderRow + 1))
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A{$headerRow}:{$lastColumn}".($row - 1))
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $this->addSignatures($sheet, $signatureTitles, $metadataHeaderRow + 3, $columnCount);

        $sheet->getColumnDimension('A')->setWidth(20);
        for ($index = 2; $index <= $columnCount; $index++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setWidth(45);
        }
        for ($index = $headerRow + 1; $index < $row; $index++) {
            $sheet->getRowDimension($index)->setRowHeight(120);
        }
    }

    // خانات التوقيع: صف واحد من اليمين إلى اليسار (الورقة RTL فعمود A يظهر يميناً)
    public function addSignatures(
        Worksheet $sheet,
        array $signatureTitles,
        int $startRow,
        int $columnCount
    ): void {
        if ($signatureTitles === []) {
            return;
        }

        $signatureRow = $startRow;
        $sheet->getRowDimension($signatureRow + 1)->setRowHeight(60);

        foreach ($signatureTitles as $index => $signatureTitle) {
            $column = Coordinate::stringFromColumnIndex($this->signatureColumnIndex($index, $columnCount) + 1);

            $sheet->setCellValue($column.$signatureRow, $signatureTitle);
            $sheet->getStyle($column.$signatureRow)->applyFromArray([
                'font' => ['bold' => true, 'size' => 12],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
        }
    }

    private function signatureColumnIndex(int $index, int $columnCount): int
    {
        $start = (int) floor($columnCount * $index / 3);
        $end = max($start, (int) floor($columnCount * ($index + 1) / 3) - 1);

        return min($start + (int) floor(($end - $start) / 2), $columnCount - 1);
    }

    private function makeUniqueWorksheetTitle(Spreadsheet $spreadsheet, string $title, Worksheet $currentSheet): string
    {
        $sanitized = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/u', ' ', trim($title)) ?: 'Worksheet';
        $sanitized = trim(preg_replace('/\\s+/u', ' ', $sanitized));
        $sanitized = mb_substr($sanitized, 0, 31);
        $existing = array_filter(
            $spreadsheet->getSheetNames(),
            fn (string $name): bool => $name !== $currentSheet->getTitle()
        );
        $candidate = $sanitized;
        $suffix = 2;

        while (in_array($candidate, $existing, true)) {
            $suffixText = " ({$suffix})";
            $candidate = mb_substr($sanitized, 0, 31 - mb_strlen($suffixText)).$suffixText;
            $suffix++;
        }

        return $candidate;
    }

    private function courseColor(string $courseKey, array &$colorMap): string
    {
        if (isset($colorMap[$courseKey])) {
            return $colorMap[$courseKey];
        }

        $hash = 0;
        for ($index = 0; $index < strlen($courseKey); $index++) {
            $hash = (($hash << 5) - $hash) + ord($courseKey[$index]);
            $hash &= 0xFFFFFFFF;
        }

        $hue = abs($hash) % 360;
        $colorMap[$courseKey] = $this->hslToHex("hsl({$hue}, 70%, 88%)");

        return $colorMap[$courseKey];
    }

    private function hslToHex(string $hsl): string
    {
        preg_match('/hsl\\((\\d+),\\s*(\\d+)%,\\s*(\\d+)%\\)/', $hsl, $matches);
        if ($matches === []) {
            return 'FFFFFF';
        }

        $hue = (int) $matches[1] / 360;
        $saturation = (int) $matches[2] / 100;
        $lightness = (int) $matches[3] / 100;
        $q = $lightness < 0.5
            ? $lightness * (1 + $saturation)
            : $lightness + $saturation - ($lightness * $saturation);
        $p = 2 * $lightness - $q;
        $convert = static function (float $value) use ($p, $q): float {
            if ($value < 0) {
                $value++;
            }
            if ($value > 1) {
                $value--;
            }
            if ($value < 1 / 6) {
                return $p + (($q - $p) * 6 * $value);
            }
            if ($value < 1 / 2) {
                return $q;
            }
            if ($value < 2 / 3) {
                return $p + (($q - $p) * (2 / 3 - $value) * 6);
            }

            return $p;
        };

        return sprintf('%02X%02X%02X', round($convert($hue + 1 / 3) * 255), round($convert($hue) * 255), round($convert($hue - 1 / 3) * 255));
    }

    private function metadata(array $entries): array
    {
        $courses = [];
        $rooms = [];
        $staff = [];

        foreach ($entries as $entry) {
            foreach ($entry['course_ids'] ?? [] as $courseId) {
                $courses[$courseId] = true;
            }
            if (! empty($entry['hall']['id'])) {
                $rooms['hall_'.$entry['hall']['id']] = true;
            }
            if (! empty($entry['lap']['id'])) {
                $rooms['lab_'.$entry['lap']['id']] = true;
            }
            if (! empty($entry['lecturer']['id'])) {
                $staff[$entry['lecturer']['id']] = true;
            }
        }

        return [
            'total_sessions' => count($entries),
            'total_courses' => count($courses),
            'total_rooms' => count($rooms),
            'total_staff' => count($staff),
        ];
    }
}
