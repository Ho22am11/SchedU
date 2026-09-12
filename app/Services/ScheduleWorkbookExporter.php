<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ScheduleWorkbookExporter
{
    public function addWorksheet(
        Spreadsheet $spreadsheet,
        string $worksheetTitle,
        string $title,
        string $filterString,
        array $data,
        array &$colorMap,
        array $signatureRows = []
    ): void {
        $sheet = $spreadsheet->getSheetCount() === 1
            && $spreadsheet->getActiveSheet()->getTitle() === 'Worksheet'
            ? $spreadsheet->getActiveSheet()
            : $spreadsheet->createSheet();

        $sheet->setTitle($this->makeUniqueWorksheetTitle($spreadsheet, $worksheetTitle, $sheet));
        $sheet->setRightToLeft(true);

        $columnCount = count($data['timeSlots']) + 1;
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);

        // الصفوف 1-4 محجوزة لشعاري الجامعة والكلية (المثبّتين على الصف 1)
        // حتى لا يتراكبا مع الجدول — العنوان وأسطر الفلاتر وأوراق الشبكة
        // تنزل عنها. 4×17pt ≈ 90px تتسع لأطول شعار (85px).
        $logoBandRows = 4;
        for ($index = 1; $index <= $logoBandRows; $index++) {
            $sheet->getRowDimension($index)->setRowHeight(17);
        }
        $titleRow = $logoBandRows + 1;

        $sheet->mergeCells("A{$titleRow}:{$lastColumn}{$titleRow}");
        $sheet->setCellValue("A{$titleRow}", $title);
        $sheet->getStyle("A{$titleRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 16],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']],
        ]);

        $headerRow = $titleRow + 1;
        if ($filterString !== '') {
            $sheet->mergeCells("A{$headerRow}:{$lastColumn}{$headerRow}");
            $sheet->setCellValue("A{$headerRow}", $filterString);
            $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F4FD']],
            ]);
            $headerRow++;
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

        // كل كتلة في صف مستقل، واليوم (والخلايا الأقل امتلاءً) يتمدد بدمج
        // رأسي على صفوف اليوم.
        $row = $headerRow + 1;
        foreach ($data['days'] as $day) {
            $dayRowCount = 1;
            foreach ($data['timeSlots'] as $slot) {
                $dayRowCount = max($dayRowCount, count($data['table'][$day][$slot] ?? []));
            }
            $dayEndRow = $row + $dayRowCount - 1;

            $sheet->setCellValue("A{$row}", $day);
            $sheet->getStyle("A{$row}:A{$dayEndRow}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF2CC']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            if ($dayRowCount > 1) {
                $sheet->mergeCells("A{$row}:A{$dayEndRow}");
            }

            foreach ($data['timeSlots'] as $index => $slot) {
                $column = Coordinate::stringFromColumnIndex($index + 2);
                $blocks = $data['table'][$day][$slot] ?? [];

                if ($blocks === []) {
                    if ($dayRowCount > 1) {
                        $sheet->mergeCells("{$column}{$row}:{$column}{$dayEndRow}");
                    }

                    continue;
                }

                foreach ($blocks as $blockIndex => $block) {
                    $blockRow = $row + $blockIndex;
                    $isLastBlock = $blockIndex === count($blocks) - 1;
                    $blockEndRow = $isLastBlock ? $dayEndRow : $blockRow;

                    $this->renderBlockCell($sheet, $column, $blockRow, $blockEndRow, $block, $colorMap);
                }
            }

            $row = $dayEndRow + 1;
        }

        // Table borders span the whole grid; merged cells pick up their
        // perimeter from the underlying edge cells.
        $sheet->getStyle("A{$headerRow}:{$lastColumn}".($row - 1))
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // الشعارات: الجامعة يميناً والكلية يساراً (الورقة يمينية، فالعمود A
        // يظهر يميناً وآخر عمود يساراً).
        $this->addLogos($sheet, $columnCount);

        $this->addSignatures($sheet, $signatureRows, $row + 1, $columnCount);

        $sheet->getColumnDimension('A')->setWidth(20);
        for ($index = 2; $index <= $columnCount; $index++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setWidth(45);
        }
        for ($index = $headerRow + 1; $index < $row; $index++) {
            $sheet->getRowDimension($index)->setRowHeight(120);
        }
    }

    private function renderBlockCell(
        Worksheet $sheet,
        string $column,
        int $startRow,
        int $endRow,
        array $block,
        array &$colorMap
    ): void {
        $cell = "{$column}{$startRow}";
        $sheet->setCellValue($cell, implode("\n", $block['html']));
        $sheet->getStyle($cell)->getAlignment()
            ->setWrapText(true)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);

        if ($endRow > $startRow) {
            $sheet->mergeCells("{$column}{$startRow}:{$column}{$endRow}");
        }

        if (! empty($block['is_reserved'])) {
            $fill = $sheet->getStyle("{$cell}:{$column}{$endRow}")->getFill();
            $fill->setFillType(Fill::FILL_SOLID);
            $fill->getStartColor()->setRGB('E7E5E4');
            $sheet->getStyle($cell)->getFont()->setBold(true);

            return;
        }

        if (! empty($block['is_blocker'])) {
            $fill = $sheet->getStyle("{$cell}:{$column}{$endRow}")->getFill();
            $fill->setFillType(Fill::FILL_SOLID);
            $fill->getStartColor()->setRGB('FEE2E2');
            $sheet->getStyle($cell)->getFont()->setBold(true);

            return;
        }

        $courseKey = $block['course_key'] ?? '';
        if ($courseKey !== '') {
            $fill = $sheet->getStyle("{$cell}:{$column}{$endRow}")->getFill();
            $fill->setFillType(Fill::FILL_SOLID);
            $fill->getStartColor()->setRGB($this->courseColor($courseKey, $colorMap));
        }
    }

    /**
     * شعارا الجامعة والكلية على كل ورقة: الجامعة يُثبَّت على A1 (يمين
     * الصفحة في الورقة اليمينية) والكلية على آخر عمود (يسار الصفحة).
     */
    public function addLogos(Worksheet $sheet, int $columnCount): void
    {
        $this->appendLogo($sheet, 'du-logo.png', 'A1', 70);
        $this->appendLogo(
            $sheet,
            'cai-logo.png',
            Coordinate::stringFromColumnIndex($columnCount).'1',
            85
        );
    }

    private function appendLogo(Worksheet $sheet, string $filename, string $coordinate, int $height): void
    {
        $path = storage_path('app/public/'.$filename);
        if (! file_exists($path)) {
            return;
        }

        $drawing = new Drawing;
        $drawing->setPath($path);
        $drawing->setHeight($height);
        $drawing->setCoordinates($coordinate);
        $drawing->setOffsetY(2);
        $sheet->getDrawingCollection()->append($drawing);
    }

    /**
     * خانات التوقيع: صف واحد من اليمين إلى اليسار (الورقة RTL فعمود A يظهر
     * يميناً) — العنوان، تحته مساحة فارغة للتوقيع، ثم اسم المسؤول.
     *
     * @param  array<int, array{title: string, name: string}>  $signatureRows
     */
    public function addSignatures(
        Worksheet $sheet,
        array $signatureRows,
        int $startRow,
        int $columnCount
    ): void {
        if ($signatureRows === []) {
            return;
        }

        $titleRow = $startRow;
        $signRow = $startRow + 1;
        $nameRow = $startRow + 2;
        $sheet->getRowDimension($signRow)->setRowHeight(60);

        foreach ($signatureRows as $index => $signatureRow) {
            $column = Coordinate::stringFromColumnIndex($this->signatureColumnIndex($index, $columnCount) + 1);

            $sheet->setCellValue($column.$titleRow, $signatureRow['title']);
            $sheet->getStyle($column.$titleRow)->applyFromArray([
                'font' => ['bold' => true, 'size' => 12],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $sheet->setCellValue($column.$nameRow, $signatureRow['name']);
            $sheet->getStyle($column.$nameRow)->applyFromArray([
                'font' => ['bold' => true, 'size' => 11],
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
}
