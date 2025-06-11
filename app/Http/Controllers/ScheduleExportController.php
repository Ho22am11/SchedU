<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
// use PhpOffice\PhpSpreadsheet\Spreadsheet;
// use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
class ScheduleExportController extends Controller
{
    private function loadData()
    {
        $filePath = storage_path('app/public/test_schedule.json');
    
        if (!file_exists($filePath)) {
            return response()->json(['error' => 'File not found at: ' . $filePath], 404);
        }
    
        $json = file_get_contents($filePath);
        $data = json_decode($json, true);
    
        if (!isset($data['schedule'])) {
            return response()->json(['error' => 'Schedule data missing'], 500);
        }
    
        return $data['schedule'];
    }
    
// good for pdf
    private function buildTable()
    {
        $sessions = $this->loadData();
        $days = ['sunday' => 'الأحد', 'monday' => 'الاثنين', 'tuesday' => 'الثلاثاء', 'wednesday' => 'الأربعاء', 'thursday' => 'الخميس'];
        $timeSlots = ['09:00-11:00', '11:00-13:00', '13:00-15:00', '15:00-17:00'];

        $table = [];
        foreach ($timeSlots as $slot) {
            foreach ($days as $en => $ar) {
                $table[$slot][$ar] = '';
            }
        }

        foreach ($sessions as $s) {
            $slot = "{$s['time_slot']['start_time']}-{$s['time_slot']['end_time']}";
            $day = strtolower($s['time_slot']['day']);
            $dayAr = $days[$day] ?? '';
        
            $name = $s['course']['name'];
            $type = $s['session_type']; 
            $courseKey = $s['course']['code']; 
        
            $teacher = $s['staff']['academic_degree']['prefix'] . ' ' . $s['staff']['name'];
            $room = $s['hall']['name'] ?? ($s['room']['name'] ?? '');
        
            $entry = "$name<br>$teacher<br>قاعة $room";
        
       
            if (empty($table[$slot][$dayAr])) {
                $table[$slot][$dayAr] = [
                    'html' => [$entry],
                    'course_key' => $courseKey,
                    'type' => $type
                ];
            } else {
            
                $table[$slot][$dayAr]['html'][] = $entry;
            }
        }
        

        return $table;
    }


    // Good for excel
    // private function buildTable()
    // {
    //     $sessions = $this->loadData();
    //     $days = ['sunday' => 'الأحد', 'monday' => 'الاثنين', 'tuesday' => 'الثلاثاء', 'wednesday' => 'الأربعاء', 'thursday' => 'الخميس'];
    //     $timeSlots = ['09:00-11:00', '11:00-13:00', '13:00-15:00', '15:00-17:00'];
    
    //     $table = [];
    //     foreach ($timeSlots as $slot) {
    //         foreach ($days as $en => $ar) {
    //             $table[$slot][$ar] = '';
    //         }
    //     }
    
    //     foreach ($sessions as $s) {
    //         $slot = "{$s['time_slot']['start_time']}-{$s['time_slot']['end_time']}";
    //         $day = strtolower($s['time_slot']['day']);
    //         $dayAr = $days[$day] ?? '';
        
    //         $name = $s['course']['name'];
    //         $type = $s['session_type'];
    //         $courseKey = $s['course']['code'];
        
    //         $teacher = $s['staff']['academic_degree']['prefix'] . ' ' . $s['staff']['name'];
    //         $room = $s['hall']['name'] ?? ($s['room']['name'] ?? '');
        
    //         // التعديل هنا: استخدام فواصل مع شرطات
    //         $entry = "$name\n$teacher\nقاعة $room";
        
    //         if (empty($table[$slot][$dayAr])) {
    //             $table[$slot][$dayAr] = [
    //                 'html' => [$entry],
    //                 'course_key' => $courseKey,
    //                 'type' => $type
    //             ];
    //         } else {
    //             // إضافة فاصل بين المحاضرات
    //             $table[$slot][$dayAr]['html'][] = "────────────\n$entry"; 
    //         }
    //     }
        
    //     return $table;
    // }


    public function exportPdf()
    {
        $table = $this->buildTable();
        $days = ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس'];
        $timeSlots = ['09:00-11:00', '11:00-13:00', '13:00-15:00', '15:00-17:00'];
    
        // بدء الـ HTML مع تنسيق الطباعة والهوامش
        $html = '
        <style>
            body { font-family: "Arial"; direction: rtl; }
            .header { width: 100%; margin-bottom: 10px; }
            .logo { float: left; }
            .title { float: right; font-size: 20px; font-weight: bold; margin-top: 30px; }
            table { border-collapse: collapse; width: 100%; margin-top: 40px; page-break-inside: avoid; }
            th, td { border: 1px solid #000; padding: 8px; text-align: center; vertical-align: top; }
            hr { border: 0; border-top: 1px solid #999; margin: 4px 0; }
        </style>
        ';
    
       
        $logoPath = storage_path('app/public/logo1.png');
    
        $base64Logo = '';
        if (file_exists($logoPath)) {
            $base64Logo = base64_encode(file_get_contents($logoPath));
        }
        
    
        $html .= '
        <div class="header">
            <div class="title">جدول المحاضرات الأسبوعي</div>
            <div class="logo">
                <img src="data:image/png;base64,' . $base64Logo . '" style="height: 70px;">
            </div>
            <div style="clear: both;"></div>
        </div>';
    
    
    
        $html .= '<table><thead><tr><th>اليوم \ الوقت</th>';
        foreach ($timeSlots as $slot) {
            $html .= "<th>$slot</th>";
        }
        $html .= '</tr></thead><tbody>';
    
        $colorMap = [];
        $colors = ['#c8e6c9', '#ffecb3', '#bbdefb', '#f8bbd0', '#d1c4e9', '#ffe0b2', '#b2dfdb'];
        $colorIndex = 0;
    
        foreach ($days as $day) {
            $html .= "<tr><td><strong>$day</strong></td>";
            foreach ($timeSlots as $slot) {
                $entry = $table[$slot][$day] ?? '';
                if (empty($entry)) {
                    $html .= '<td></td>';
                } else {
                    $courseKey = $entry['course_key'] ?? '';
                    if (!isset($colorMap[$courseKey])) {
                        $colorMap[$courseKey] = $colors[$colorIndex % count($colors)];
                        $colorIndex++;
                    }
                    $bg = $colorMap[$courseKey];
                    $cellContent = implode('<hr>', $entry['html']);
                    $html .= "<td style='background-color: $bg;'>$cellContent</td>";
                }
            }
            $html .= '</tr>';
        }
    
        $html .= '</tbody></table>';
    
        // إعداد mPDF وتحديد الصفحة أفقياً + منع التقطيع
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'margin_top' => 10,
            'margin_bottom' => 10,
            'margin_left' => 10,
            'margin_right' => 10,
        ]);
    
        // أهم سطر يمنع تقسيم الجدول
        $mpdf->SetAutoPageBreak(true, 10);
        $mpdf->WriteHTML($html);
    
        return response($mpdf->Output('', 'S'))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="schedule.pdf"');
    }

    // public function exportExcel()
    // {
    //     $table = $this->buildTable();
    //     $spreadsheet = new Spreadsheet();
    //     $sheet = $spreadsheet->getActiveSheet();
    //     $sheet->setRightToLeft(true); // دعم العربية
    
    //     $days = ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس'];
    //     $timeSlots = ['09:00-11:00', '11:00-13:00', '13:00-15:00', '15:00-17:00'];
    
    //     // ألوان لكل كورس
    //     $colorMap = [];
    //     $colors = ['c8e6c9', 'ffecb3', 'bbdefb', 'f8bbd0', 'd1c4e9', 'ffe0b2', 'b2dfdb'];
    //     $colorIndex = 0;
    
    //     // --- 1. عنوان الجدول
    //     $sheet->mergeCells('A1:F1');
    //     $sheet->setCellValue('A1', 'جدول المحاضرات الأسبوعي');
    //     $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    //     $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');
    
    //     // --- 2. الصف الثاني: الوقت
    //     $sheet->setCellValue('A2', 'اليوم \ الوقت');
    //     foreach ($timeSlots as $i => $slot) {
    //         $col = chr(ord('B') + $i); // B, C, D...
    //         $sheet->setCellValue($col . '2', $slot);
    //     }
    
    //     // --- 3. تعبئة الأيام والبيانات
    //     $rowIndex = 3;
    //     foreach ($days as $day) {
    //         $sheet->setCellValue("A{$rowIndex}", $day);
    //         foreach ($timeSlots as $i => $slot) {
    //             $col = chr(ord('B') + $i);
    //             $entry = $table[$slot][$day] ?? '';
    //             if (!empty($entry)) {
    //                 $courseKey = $entry['course_key'] ?? '';
    //                 $cellContent = implode("\n------------------\n", $entry['html']);
    //                 $cell = $col . $rowIndex;
    
    //                 // كتابة البيانات
    //                 $sheet->setCellValue($cell, $cellContent);
    //                 $sheet->getStyle($cell)->getAlignment()->setWrapText(true)->setHorizontal('center')->setVertical('top');
    
    //                 // لون الكورس
    //                 if (!isset($colorMap[$courseKey])) {
    //                     $colorMap[$courseKey] = $colors[$colorIndex % count($colors)];
    //                     $colorIndex++;
    //                 }
    //                 $bgColor = $colorMap[$courseKey];
    //                 $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($bgColor);
    //             }
    //         }
    //         $rowIndex++;
    //     }
    
    //     // --- 4. تنسيق الحدود
    //     $lastCol = chr(ord('A') + count($timeSlots));
    //     $sheet->getStyle("A2:{$lastCol}" . ($rowIndex - 1))
    //         ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    
    //     // --- 5. تعديل حجم الأعمدة
    //     foreach (range('A', $lastCol) as $col) {
    //         $sheet->getColumnDimension($col)->setAutoSize(true);
    //     }
    
    //     // --- 6. حفظ الملف وتحميله
    //     $fileName = 'schedule.xlsx';
    //     $tempPath = tempnam(sys_get_temp_dir(), 'excel_');
    //     $writer = new Xlsx($spreadsheet);
    //     $writer->save($tempPath);
    
    //     return response()->download($tempPath, $fileName)->deleteFileAfterSend(true);
    // }



    public function exportExcel()
    {
        $table = $this->buildTable();
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
    
        $days = ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس'];
        $timeSlots = ['09:00-11:00', '11:00-13:00', '13:00-15:00', '15:00-17:00'];
    
        $colorMap = [];
        $colors = ['c8e6c9', 'ffecb3', 'bbdefb', 'f8bbd0', 'd1c4e9', 'ffe0b2', 'b2dfdb'];
        $colorIndex = 0;
    
        // --- العنوان الرئيسي ---
        $sheet->mergeCells('A1:F1');
        $sheet->setCellValue('A1', 'جدول المحاضرات الأسبوعي');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');
    
        // --- رؤوس الأعمدة ---
        $sheet->setCellValue('A2', 'اليوم \ الوقت');
        foreach ($timeSlots as $i => $slot) {
            $col = chr(ord('B') + $i);
            $sheet->setCellValue($col . '2', $slot);
        }
    
        // --- تعبئة البيانات ---
        $rowIndex = 3;
        foreach ($days as $day) {
            $sheet->setCellValue("A{$rowIndex}", $day);
            foreach ($timeSlots as $i => $slot) {
                $col = chr(ord('B') + $i);
                $entry = $table[$slot][$day] ?? '';
                if (!empty($entry)) {
                    $courseKey = $entry['course_key'] ?? '';
                    $cellContent = implode("\n", $entry['html']); 
                    $cell = $col . $rowIndex;
    
                
                    $sheet->setCellValue($cell, $cellContent);
                    $sheet->getStyle($cell)
                        ->getAlignment()
                        ->setWrapText(true)
                        ->setVertical('top')
                        ->setHorizontal('right'); 
    
                    
                    if (!isset($colorMap[$courseKey])) {
                        $colorMap[$courseKey] = $colors[$colorIndex % count($colors)];
                        $colorIndex++;
                    }
                    $sheet->getStyle($cell)
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setRGB($colorMap[$courseKey]);
                }
            }
            $rowIndex++;
        }
    
        // --- تنسيق الحدود ---
        $lastCol = chr(ord('A') + count($timeSlots));
        $sheet->getStyle("A2:{$lastCol}" . ($rowIndex - 1))
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    
        // --- تعديل الأعمدة ---
        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setWidth(30); 
        }
    
        // --- الحفظ ---
        $fileName = 'schedule.xlsx';
        $tempPath = tempnam(sys_get_temp_dir(), 'excel_');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);
    
        return response()->download($tempPath, $fileName)->deleteFileAfterSend(true);
    }

}
