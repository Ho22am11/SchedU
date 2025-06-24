<?php
namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use App\Models\Schedule;
use App\Models\ScheduleEntry;
use App\Models\Lecturer;
use App\Models\Hall;
use App\Models\Lap;
use App\Models\Department;
use App\Models\Academic;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ScheduleExportController extends Controller
{
    // دالة لتحميل البيانات مع تطبيق الفلاتر
    private function loadFilteredData($scheduleId, $filters)
    {
        $query = ScheduleEntry::with([
            'course' => function($query) {
                $query->select('id', 'name_ar', 'name_en', 'code');
            },
            'lecturer.academicDegree' => function($query) {
                $query->select('id', 'prefix');
            },
            'lecturer' => function($query) {
                $query->select('id', 'name', 'department_id', 'academic_id');
            },
            'hall' => function($query) {
                $query->select('id', 'name');
            },
            'lap' => function($query) {
                $query->select('id', 'name');
            },
            'department' => function($query) {
                $query->select('id', 'name_ar', 'name');
            },
            'academic' => function($query) {
                $query->select('id', 'name_ar');
            }
        ])
        ->where('schedule_id', $scheduleId)
        ->select('id', 'course_id', 'lecturer_id', 'hall_id', 'lap_id', 
                 'department_id', 'academic_id', 'academic_level', 
                 'startTime', 'endTime', 'Day', 'group_number', 'total_groups');

        // تطبيق الفلاتر
        if (!empty($filters['staff_id'])) {
            $query->where('lecturer_id', $filters['staff_id']);
        }
        if (!empty($filters['hall_id'])) {
            $query->where('hall_id', $filters['hall_id']);
        }
        if (!empty($filters['lab_id'])) {
            $query->where('lap_id', $filters['lab_id']);
        }
        if (!empty($filters['academic_list_id'])) {
            $query->where('academic_id', $filters['academic_list_id']);
        }
        if (!empty($filters['academic_level'])) {
            $query->where('academic_level', $filters['academic_level']);
        }
        if (!empty($filters['department_id'])) {
            $query->whereHas('lecturer', function($q) use ($filters) {
                $q->where('department_id', $filters['department_id']);
            });
        }

        return $query->get()->toArray();
    }

    // دالة لبناء الجدول من البيانات
    private function buildTable($entries, $exportType = 'pdf')
    {
        $daysMap = [
            'saturday' => 'السبت',
            'sunday' => 'الأحد',
            'monday' => 'الاثنين',
            'tuesday' => 'الثلاثاء',
            'wednesday' => 'الأربعاء',
            'thursday' => 'الخميس',
            'friday' => 'الجمعة'
        ];
        
        // إنشاء time slots مسبقا
        $timeSlots = array_map(function($hour) {
            $start = str_pad($hour, 2, '0', STR_PAD_LEFT) . ':00';
            $end = str_pad($hour + 2, 2, '0', STR_PAD_LEFT) . ':00';
            return $start . '-' . $end;
        }, range(9, 18, 2));
        
        // تهيئة الجدول باستخدام array_fill
        $table = array_fill_keys(array_values($daysMap), 
                  array_fill_keys($timeSlots, ''));
        
        foreach ($entries as $entry) {
            $dayAr = $daysMap[strtolower($entry['Day'])] ?? '';
            if (empty($dayAr)) continue;
            
            $slot = ($entry['startTime'] ? substr($entry['startTime'], 0, 5) : '') . '-' . 
                    ($entry['endTime'] ? substr($entry['endTime'], 0, 5) : '');
            
            // تجميع بيانات الخلية
            $entryContent = $this->buildCellContent($entry, $exportType);
            
            if (!empty($entryContent)) {
                $courseKey = $entry['course']['code'] ?? '';
                
                if (empty($table[$dayAr][$slot])) {
                    $table[$dayAr][$slot] = [
                        'html' => [$entryContent],
                        'course_key' => $courseKey
                    ];
                } else {
                    // التعديل هنا: استبدال الخط بكلمة "أو"
                    if ($exportType === 'pdf') {
                        $separator = '<div style="text-align:center; font-weight:bold; margin:5px 0;">________________________</br></div>';
                    } else {
                        $separator = "\n────────────\n";
                    }
                    
                    $table[$dayAr][$slot]['html'][] = $separator . $entryContent;
                }
            }
        }

        return [
            'table' => $table,
            'timeSlots' => $timeSlots,
            'days' => array_values($daysMap)
        ];
    }

    // دالة مساعدة لبناء محتوى الخلية
    private function buildCellContent($entry, $exportType)
    {
        $parts = [];
        $esc = function($value) {
            return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        };

        // معلومات الأكاديمية
        if (!empty($entry['academic']['name_ar'])) {
            $academicInfo = !empty($entry['academic_level']) ? 
                "المستوى {$esc($entry['academic_level'])} - {$esc($entry['academic']['name_ar'])}" : 
                $esc($entry['academic']['name_ar']);
    
            if ($exportType === 'pdf') {
                $academicInfo = "<div style='font-size:10px; font-weight:bold;'>{$academicInfo}</div>";
            }
            $parts[] = $academicInfo;
        }
    
        // معلومات المادة
        $courseName = $esc($entry['course']['name_ar'] ?? $entry['course']['name_en'] ?? '');
        if (!empty($courseName)) {
            $courseCode = $esc($entry['course']['code'] ?? '');
            $courseInfo = $exportType === 'pdf' ? 
                "<div style='font-weight:bold; font-size:14px;'>{$courseName}" . 
                (!empty($courseCode) ? " <span style='font-weight:bold;font-size:12px;'>{$courseCode}</span>" : '') . 
                "</div>" : 
                $courseName . (!empty($courseCode) ? " ({$courseCode})" : '');
    
            $parts[] = $courseInfo;
        }
    
        // معلومات المحاضر (الدكتور)
        $degreePrefix = $esc($entry['lecturer']['academic_degree']['prefix'] ?? '');
        $lecturerName = $esc($entry['lecturer']['name'] ?? $entry['lecturer']['name_ar'] ?? '');
        $staffName = trim($degreePrefix . ' ' . $lecturerName);
    
        if (!empty($staffName)) {
            $staffDisplay = $exportType === 'pdf'
                ? "<div style='font-weight:bold;font-size:12px;'>الدكتور: {$staffName}</div>"
                : "الدكتور: {$staffName}";
            $parts[] = $staffDisplay;
        }
    
        // معلومات مكان المادة (قاعة أو معمل)
        $hallName = $esc($entry['hall']['name'] ?? '');
        $lapName = $esc($entry['lap']['name'] ?? '');
        $room = '';
        if (!empty($hallName)) {
            $room = 'قاعة: ' . $hallName;
        } elseif (!empty($lapName)) {
            $room = 'معمل: ' . $lapName;
        }
    
        if (!empty($room)) {
            $roomDisplay = $exportType === 'pdf'
                ? "<div style='font-weight:bold;font-size:12px;'>{$room}</div>"
                : $room;
            $parts[] = $roomDisplay;
        }
    
        // معلومات المجموعة
        if (isset($entry['group_number']) && isset($entry['total_groups'])) {
            $group = "المجموعة {$esc($entry['group_number'])} من {$esc($entry['total_groups'])}";
            if ($exportType === 'pdf') {
                $group = "<div style='font-size:12px;'>{$group}</div>";
            }
            $parts[] = $group;
        }
    
        return $exportType === 'pdf' ? implode('', $parts) : implode("\n", $parts);
    }

    // تصدير PDF مع الفلاتر
    public function exportPdfWithFilters(Request $request)
    {
        set_time_limit(300); // زيادة وقت التنفيذ إلى 5 دقائق
        
        // التحقق من صحة البيانات
        $validated = $request->validate([
            'schedule_id' => 'required|exists:schedules,id',
        ]);

        $scheduleId = $validated['schedule_id'];
        $filters = $request->only([
            'staff_id', 'hall_id', 'lab_id', 
            'academic_list_id', 'academic_level', 'department_id'
        ]);

        // جلب البيانات المفلترة
        $entries = $this->loadFilteredData($scheduleId, $filters);
        
        // بناء الجدول
        $data = $this->buildTable($entries, 'pdf');
        
        // عنوان الجدول
        $schedule = Schedule::select('nameAr')->find($scheduleId);
        $title = $schedule ? $schedule->nameAr : 'الجدول ' . $scheduleId;
        
        // إنشاء PDF
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'margin_top' => 15,
            'margin_bottom' => 15,
            'margin_left' => 10,
            'margin_right' => 10,
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true
        ]);
    
        $mpdf->SetAutoPageBreak(true, 15);
        $mpdf->WriteHTML($this->generatePdfHtml($title, $data['table'], $data['timeSlots'], $data['days']));
    
        // اسم الملف
        $filename = 'schedule_' . $scheduleId . '_' . time() . '.pdf';
    
        return response($mpdf->Output('', 'S'))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
    
    // تصدير Excel مع الفلاتر
    public function exportExcelWithFilters(Request $request)
    {
        set_time_limit(300); // زيادة وقت التنفيذ إلى 5 دقائق
        
        // التحقق من صحة البيانات
        $validated = $request->validate([
            'schedule_id' => 'required|exists:schedules,id',
        ]);

        $scheduleId = $validated['schedule_id'];
        $filters = $request->only([
            'staff_id', 'hall_id', 'lab_id', 
            'academic_list_id', 'academic_level', 'department_id'
        ]);

        // جلب البيانات المفلترة
        $entries = $this->loadFilteredData($scheduleId, $filters);
        
        // بناء الجدول
        $data = $this->buildTable($entries, 'excel');
        
        // إنشاء ملف Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
    
        // عنوان الجدول
        $schedule = Schedule::select('nameAr')->find($scheduleId);
        $title = $schedule ? $schedule->nameAr : 'الجدول ' . $scheduleId;
    
        $colCount = count($data['timeSlots']) + 1;
        $lastCol = chr(65 + $colCount - 1);
    
        $sheet->mergeCells('A1:' . $lastCol . '1');
        $sheet->setCellValue('A1', $title);
        
        // تنسيق العنوان
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16],
            'alignment' => ['horizontal' => 'center'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']]
        ]);
    
        // رؤوس الأعمدة
        $sheet->setCellValue('A2', 'اليوم / الوقت');
        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2EFDA']],
            'alignment' => ['horizontal' => 'center']
        ];
        
        $sheet->getStyle('A2')->applyFromArray($headerStyle);
        
        $colIndex = 1;
        foreach ($data['timeSlots'] as $slot) {
            $col = chr(65 + $colIndex);
            $sheet->setCellValue($col . '2', $slot);
            $sheet->getStyle($col . '2')->applyFromArray($headerStyle);
            $colIndex++;
        }
    
        // ملء البيانات
        $colors = ['FF6B6B', '4ECDC4', '45B7D1', 'FFA07A', '98D8C8', 'D4A5A5'];
        $colorMap = [];
        $colorIndex = 0;
    
        $rowIndex = 3;
        foreach ($data['days'] as $day) {
            $sheet->setCellValue("A{$rowIndex}", $day);
            $sheet->getStyle("A{$rowIndex}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF2CC']],
                'alignment' => ['horizontal' => 'center']
            ]);
            
            $colIndex = 1;
            foreach ($data['timeSlots'] as $slot) {
                $col = chr(65 + $colIndex);
                $cell = $col . $rowIndex;
                $entry = $data['table'][$day][$slot] ?? '';
                
                if (!empty($entry)) {
                    $sheet->setCellValue($cell, implode("\n", $entry['html']));
                    $sheet->getStyle($cell)->getAlignment()
                        ->setWrapText(true)
                        ->setVertical('center')
                        ->setHorizontal('center');
                    
                    $courseKey = $entry['course_key'] ?? '';
                    if (!isset($colorMap[$courseKey])) {
                        $colorMap[$courseKey] = $colors[$colorIndex % count($colors)];
                        $colorIndex++;
                    }
                    
                    $sheet->getStyle($cell)->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setRGB($colorMap[$courseKey]);
                }
                $colIndex++;
            }
            $rowIndex++;
        }
    
        // تنسيق الجدول
        $tableRange = "A2:{$lastCol}" . ($rowIndex - 1);
        $sheet->getStyle($tableRange)->getBorders()
            ->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    
        $sheet->getColumnDimension('A')->setWidth(15);
        foreach (range('B', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setWidth(30);
        }
    
        for ($i = 3; $i < $rowIndex; $i++) {
            $sheet->getRowDimension($i)->setRowHeight(80);
        }
    
        // إنشاء الملف
        $filename = 'schedule_' . $scheduleId . '_' . time() . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();
        
        return response($content)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    // دالة مساعدة لإنشاء HTML لـ PDF
    private function generatePdfHtml($title, $table, $timeSlots, $days)
    {
        $colors = ['#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', '#98D8C8', '#D4A5A5'];
        $colorMap = [];
        $colorIndex = 0;
        
        $html = '<!DOCTYPE html><html><head>
            <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
            <style>
                body { font-family: "Arial", "DejaVu Sans"; direction: rtl; }
                .header { margin-bottom: 10px; text-align: center; }
                .title { font-size: 22px; font-weight: bold; }
                table { border-collapse: collapse; width: 100%; margin-top: 20px; }
                th, td { border: 1px solid #333; padding: 8px; text-align: center; vertical-align: top; }
                th { background-color: #f2f2f2; }
                .time-header { background-color: #e0e0e0; }
                .day-header { background-color: #d0d0d0; }
                .entry-content { font-size: 12px; }
                .course-name { font-weight: bold; font-size: 14px; margin-bottom: 3px; }
                .lecturer, .room { font-weight: bold; }
                .academic-info { font-weight: bold; font-size: 10px; margin-bottom: 3px; }
                .group-info { font-size: 12px; margin-top: 3px; }
                /* تنسيق جديد لكلمة "أو" */
                .or-separator {
                    text-align: center; 
                    font-weight: bold;
                    margin: 8px 0;
                    color: #555;
                }
            </style>
        </head><body>';
        
        $html .= '<div class="header">
            <div class="title">' . htmlspecialchars($title) . '</div>
            <div style="font-size: 16px; margin-top: 5px;">للعام الجامعي ' . date('Y') . '/' . (date('Y')+1) . ' - الفصل الدراسي الأول</div>
        </div>';
        
        $html .= '<table><thead><tr><th class="day-header">اليوم / الوقت</th>';
        foreach ($timeSlots as $slot) {
            $html .= '<th class="time-header">' . htmlspecialchars($slot) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        
        foreach ($days as $day) {
            $html .= '<tr><td class="day-header"><strong>' . htmlspecialchars($day) . '</strong></td>';
            
            foreach ($timeSlots as $slot) {
                $entry = $table[$day][$slot] ?? '';
                if (empty($entry)) {
                    $html .= '<td></td>';
                    continue;
                }
                
                $courseKey = $entry['course_key'] ?? '';
                if (!isset($colorMap[$courseKey])) {
                    $colorMap[$courseKey] = $colors[$colorIndex % count($colors)];
                    $colorIndex++;
                }
                
                $cellContent = '<div class="entry-content">' . implode('', $entry['html']) . '</div>';
                $html .= '<td style="background-color:' . $colorMap[$courseKey] . ';">' . $cellContent . '</td>';
            }
            
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        $html .= '<div style="text-align:center; margin-top:20px; font-size:12px;">
            تم إنشاء الجدول في ' . date('Y-m-d H:i') . ' | نظام جدولة المحاضرات
        </div>';
        $html .= '</body></html>';
        
        return $html;
    }
    
    // دالة مساعدة للحصول على الشعارات
    private function getBase64Logo($filename)
    {
        $path = storage_path('app/public/' . $filename);
        if (file_exists($path)) {
            $mime = mime_content_type($path);
            $data = base64_encode(file_get_contents($path));
            return "data:{$mime};base64,{$data}";
        }
        return '';
    }
}
