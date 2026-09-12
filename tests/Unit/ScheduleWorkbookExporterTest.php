<?php

namespace Tests\Unit;

use App\Services\ScheduleWorkbookExporter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * اختبار Laravel (لا PHPUnit الخام): مُصدِّر الدفتر يقرأ الشعارات عبر
 * storage_path() التي تتطلب حاوية التطبيق مُهيأة.
 */
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
                        ['html' => ['مقرر تجريبي'], 'course_key' => 'CS101'],
                    ],
                ],
            ],
        ];

        $exporter->addWorksheet($workbook, 'جدول: تجريبي', 'عنوان', '', $data, $colorMap);
        $exporter->addWorksheet($workbook, 'جدول: تجريبي', 'عنوان', '', $data, $colorMap);

        $sheetNames = $workbook->getSheetNames();

        $this->assertSame(2, $workbook->getSheetCount());
        $this->assertStringNotContainsString(':', $sheetNames[0]);
        $this->assertNotSame($sheetNames[0], $sheetNames[1]);
        // الصفوف 1-4 لشعارَي الجامعة والكلية، وبلا سطر فلاتر: العنوان في
        // الصف 5 وترويسة الشبكة في الصف 6 وشبكة اليوم يبدأ من الصف 7.
        $this->assertSame('الأحد', $workbook->getSheet(0)->getCell('A7')->getValue());
        $this->assertSame(
            $workbook->getSheet(0)->getStyle('B7')->getFill()->getStartColor()->getRGB(),
            $workbook->getSheet(1)->getStyle('B7')->getFill()->getStartColor()->getRGB()
        );
    }

    public function test_it_stacks_parallel_blocks_in_separate_rows_under_each_other(): void
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
                        ['html' => ['مقرر أول'], 'course_key' => 'CS101'],
                        ['html' => ['مقرر ثانٍ'], 'course_key' => 'CS102'],
                    ],
                    '11:00-13:00' => [],
                ],
            ],
        ];

        $exporter->addWorksheet($workbook, 'جدول تجريبي', 'عنوان', '', $data, $colorMap);

        $sheet = $workbook->getSheet(0);

        // كل كتلة في صف مستقلة تحت الآخر، واليوم مدموج على صفّيه، والفترة
        // الفارغة مدموجة رأسياً بدل أن تترك ثقباً في الشبكة. (الشبكة يبدأ
        // من الصف 7 بعد صفوف الشعارات والعنوان والترويسة.)
        $this->assertSame('الأحد', $sheet->getCell('A7')->getValue());
        $this->assertSame('مقرر أول', $sheet->getCell('B7')->getValue());
        $this->assertSame('مقرر ثانٍ', $sheet->getCell('B8')->getValue());
        $this->assertContains('A7:A8', $sheet->getMergeCells());
        $this->assertContains('C7:C8', $sheet->getMergeCells());
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
                        ['html' => ['مقرر تجريبي'], 'course_key' => 'CS101'],
                    ],
                    '11:00-13:00' => [],
                ],
            ],
        ];
        $signatureRows = [
            ['title' => 'منسق الجداول', 'name' => 'د. أحمد محمد ربيع'],
            ['title' => 'وكيل الكلية', 'name' => 'أ.د. سامي محمد دراز'],
            ['title' => 'عميد الكلية', 'name' => 'أ.د. وائل عبدالقادر عوض'],
        ];

        $exporter->addWorksheet($workbook, 'جدول تجريبي', 'عنوان', '', $data, $colorMap, $signatureRows);

        $sheet = $workbook->getSheet(0);

        // الشبكة ينتهي عند الصف 7، فالعناوين في الصف 9 (بعد صف فاصل)، وتحتها
        // صف التوقيع الفارغ (ارتفاع 60) ثم أسماء المسؤولين في الصف 11. الورقة
        // RTL فالعمود A يظهر يميناً: أول عنوان في أقصى اليمين.
        $this->assertSame('منسق الجداول', $sheet->getCell('A9')->getValue());
        $this->assertSame('وكيل الكلية', $sheet->getCell('B9')->getValue());
        $this->assertSame('عميد الكلية', $sheet->getCell('C9')->getValue());
        $this->assertSame(60.0, $sheet->getRowDimension(10)->getRowHeight());
        $this->assertSame('د. أحمد محمد ربيع', $sheet->getCell('A11')->getValue());
        $this->assertSame('أ.د. سامي محمد دراز', $sheet->getCell('B11')->getValue());
        $this->assertSame('أ.د. وائل عبدالقادر عوض', $sheet->getCell('C11')->getValue());
    }
}
