<?php

namespace Tests\Unit;

use App\Services\ScheduleWorkbookExporter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

class ScheduleWorkbookExporterTest extends TestCase
{
    public function test_it_creates_unique_valid_worksheet_titles_and_keeps_course_colours_consistent(): void
    {
        $workbook = new Spreadsheet;
        $exporter = new ScheduleWorkbookExporter;
        $colorMap = [];
        $data = [
            'timeSlots' => ['09:00-11:00'],
            'days' => ['الأحد'],
            'table' => [
                'الأحد' => [
                    '09:00-11:00' => [
                        'html' => ['مقرر تجريبي'],
                        'course_key' => 'CS101',
                    ],
                ],
            ],
        ];
        $entries = [[
            'course_ids' => [1],
            'hall' => ['id' => 1],
            'lecturer' => ['id' => 1],
        ]];

        $exporter->addWorksheet(
            $workbook,
            'جدول: تجريبي',
            'عنوان',
            '',
            $data,
            $entries,
            $colorMap
        );
        $exporter->addWorksheet(
            $workbook,
            'جدول: تجريبي',
            'عنوان',
            '',
            $data,
            $entries,
            $colorMap
        );

        $sheetNames = $workbook->getSheetNames();

        $this->assertSame(2, $workbook->getSheetCount());
        $this->assertStringNotContainsString(':', $sheetNames[0]);
        $this->assertNotSame($sheetNames[0], $sheetNames[1]);
        $this->assertSame(1, $workbook->getSheet(0)->getCell('A8')->getValue());
        $this->assertSame(
            $workbook->getSheet(0)->getStyle('B3')->getFill()->getStartColor()->getRGB(),
            $workbook->getSheet(1)->getStyle('B3')->getFill()->getStartColor()->getRGB()
        );
    }

    public function test_it_places_signature_titles_in_one_row_spread_right_to_left(): void
    {
        $workbook = new Spreadsheet;
        $exporter = new ScheduleWorkbookExporter;
        $colorMap = [];
        $data = [
            'timeSlots' => ['09:00-11:00', '11:00-13:00'],
            'days' => ['الأحد'],
            'table' => [
                'الأحد' => [
                    '09:00-11:00' => [
                        'html' => ['مقرر تجريبي'],
                        'course_key' => 'CS101',
                    ],
                    '11:00-13:00' => '',
                ],
            ],
        ];
        $entries = [];

        $exporter->addWorksheet(
            $workbook,
            'جدول: تجريبي',
            'عنوان',
            '',
            $data,
            $entries,
            $colorMap,
            ['منسق الجداول', 'وكيل الكلية', 'عميد الكلية']
        );

        $sheet = $workbook->getSheet(0);

        // الورقة RTL، فالعمود A يظهر يميناً: أول عنوان في أقصى اليمين
        $this->assertSame('منسق الجداول', $sheet->getCell('A10')->getValue());
        $this->assertSame('وكيل الكلية', $sheet->getCell('B10')->getValue());
        $this->assertSame('عميد الكلية', $sheet->getCell('C10')->getValue());
        $this->assertSame(60.0, $sheet->getRowDimension(11)->getRowHeight());
    }
}
