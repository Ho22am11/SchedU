<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use App\Models\ScheduleEntry;
use App\Models\Lecturer;
use App\Models\Hall;
use App\Models\Lab;
use App\Models\Department;
use App\Models\Schedule;
use App\Models\Academic;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ScheduleExportController extends Controller
{
    private function loadData($scheduleId = null, $filterType = null, $filterId = null)
    {
        $query = ScheduleEntry::with([
            'course',
            'lecturer.academicDegree',
            'hall',
            'lab',
            'department',
            'academic'
        ]);
    
        if ($scheduleId) {
            $query->where('schedule_id', $scheduleId);
        }

        if ($filterType && $filterId) {
            switch ($filterType) {
                case 'lecturer':
                    $query->where('lecturer_id', $filterId);
                    break;
                case 'hall':
                    $query->where('hall_id', $filterId);
                    break;
                case 'lab':
                    $query->where('lab_id', $filterId);
                    break;
                case 'department':
                    $query->where('department_id', $filterId);
                    break;
                case 'level':
                    $query->where('academic_level', $filterId);
                    break;
                case 'program_list':
                    $query->where('academic_id', $filterId);
                    break;
            }
        }
    
        $entries = $query->get();
    
        $formattedData = [];
        foreach ($entries as $entry) {
            $startTime = $entry->startTime ? Carbon::parse($entry->startTime) : null;
            $endTime = $entry->endTime ? Carbon::parse($entry->endTime) : null;
            
            $lecturerName = optional($entry->lecturer)->name;
            $degreePrefix = optional(optional($entry->lecturer)->academicDegree)->prefix ?? '';
            $departmentName = optional($entry->department)->name ?? '';
            $academicName = optional($entry->academic)->name_ar ?? '';

            $staffName = $lecturerName ? $degreePrefix . ' ' . $lecturerName : '';
            
            $courseName = $entry->course->name_ar ?? $entry->course->name_en ?? '';
            
            $formattedData[] = [
                'academic' => [
                    'name_ar' => $academicName,
                    'level' => $entry->academic_level
                ],
                'course' => [
                    'name' => $courseName,
                    'code' => $entry->course->code ?? '',
                ],
                'session_type' => $entry->session_type,
                'time_slot' => [
                    'day' => $entry->Day,
                    'start_time' => $startTime ? $startTime->format('H:i') : '',
                    'end_time' => $endTime ? $endTime->format('H:i') : '',
                ],
                'staff' => [
                    'name' => $staffName,
                ],
                'hall' => $entry->hall ? ['name' => $entry->hall->name] : null,
                'room' => $entry->lab ? ['name' => $entry->lab->name] : null,
                'department' => ['name' => $departmentName],
                'group_number' => $entry->group_number ?? null,
                'total_groups' => $entry->total_groups ?? null,
            ];
        }
    
        return $formattedData;
    }

    private function buildTable($scheduleId = null, $filterType = null, $filterId = null, $exportType = 'pdf')
    {
        $sessions = $this->loadData($scheduleId, $filterType, $filterId);
        $daysMap = [
            'saturday' => 'السبت',
            'sunday' => 'الأحد',
            'monday' => 'الاثنين',
            'tuesday' => 'الثلاثاء',
            'wednesday' => 'الأربعاء',
            'thursday' => 'الخميس',
            'friday' => 'الجمعة'
        ];
        
        $timeSlots = [];
        for ($hour = 9; $hour <= 18; $hour += 2) {
            $start = str_pad($hour, 2, '0', STR_PAD_LEFT) . ':00';
            $end = str_pad($hour + 2, 2, '0', STR_PAD_LEFT) . ':00';
            $timeSlots[] = $start . '-' . $end;
        }
        
        $table = [];
        foreach ($daysMap as $ar) {
            foreach ($timeSlots as $slot) {
                $table[$ar][$slot] = '';
            }
        }
        
        foreach ($sessions as $s) {
            $slot = $s['time_slot']['start_time'] . '-' . $s['time_slot']['end_time'];
            $day = strtolower($s['time_slot']['day']);
            $dayAr = $daysMap[$day] ?? '';
            
            if (empty($dayAr)) {
                continue;
            }
            
            $name = $s['course']['name'];
            $code = $s['course']['code'];
            $type = $s['session_type']; 
            $courseKey = $code; 
            $academicLevel = $s['academic']['level'] ?? '';
            $academicName = $s['academic']['name_ar'] ?? '';
        
            $teacher = $s['staff']['name'];
            
            $room = '';
            if ($s['hall']) {
                $room = 'قاعة: ' . $s['hall']['name'];
            } elseif ($s['room']) {
                $room = 'معمل: ' . $s['room']['name'];
            }
        
            $entryParts = [];
            if (!empty($academicName)){
                $academicInfo = "";
                if (!empty($academicLevel)) {
                    $academicInfo .= "المستوى {$academicLevel} - ";
                }
                $academicInfo .= "{$academicName}";
                
                if ($exportType === 'pdf') {
                    $academicInfo = "<div style='font-size:10px; font-weight:bold;'>{$academicInfo}</div>";
                }
                $entryParts[] = $academicInfo;
            }
            
            if (!empty($name)) {
                if ($exportType === 'pdf') {
                    $courseInfo = "<div style='font-weight:bold; font-size:14px;'>{$name}";
                    if (!empty($code)) {
                        $courseInfo .= " <span style='font-weight:bold;font-size:12px;'>{$code}</span>";
                    }
                    $courseInfo .= "</div>";
                } else {
                    $courseInfo = $name;
                    if (!empty($code)) {
                        $courseInfo .= " ({$code})";
                    }
                }
                $entryParts[] = $courseInfo;
            }
            
            if (!empty($teacher)) {
                if ($exportType === 'pdf') {
                    $teacher = "<div style='font-weight:bold;font-size:12px;'>{$teacher}</div>";
                }
                $entryParts[] = $teacher;
            }
            
            if (!empty($room)) {
                if ($exportType === 'pdf') {
                    $room = "<div style='font-weight:bold;font-size:12px;'>{$room}</div>";
                }
                $entryParts[] = $room;
            }
            
            if (isset($s['group_number']) && isset($s['total_groups'])) {
                $group = "المجموعة {$s['group_number']} من {$s['total_groups']}";
                if ($exportType === 'pdf') {
                    $group = "<div style='font-size:12px;'>{$group}</div>";
                }
                $entryParts[] = $group;
            }
            
            if ($exportType === 'pdf') {
                $entry = implode('', $entryParts);
            } else {
                $entry = implode("\n", $entryParts);
            }
        
            if (!empty($entry)) {
                if (empty($table[$dayAr][$slot])) {
                    $table[$dayAr][$slot] = [
                        'html' => [$entry],
                        'course_key' => $courseKey,
                        'type' => $type
                    ];
                } else {
                    $table[$dayAr][$slot]['html'][] = $entry;
                }
            }
        }

        return [
            'table' => $table,
            'timeSlots' => $timeSlots,
            'days' => array_values($daysMap)
        ];
    }

    public function exportPdf($scheduleId = null, $filterType = null, $filterId = null)
    {
        $data = $this->buildTable($scheduleId, $filterType, $filterId, 'pdf');
        $table = $data['table'];
        $timeSlots = $data['timeSlots'];
        $days = $data['days'];
    
        $title = 'جدول المحاضرات الأسبوعي';
        
        if ($scheduleId) {
            $schedule = Schedule::find($scheduleId);
            $title = $schedule ? $schedule->nameAr : 'الجدول ' . $scheduleId;
        } elseif ($filterType && $filterId) {
            switch ($filterType) {
                case 'lecturer':
                    $lecturer = Lecturer::findOrFail($filterId);
                    $title = $lecturer->academicDegree->prefix . ' ' . $lecturer->name;
                    break;
                case 'hall':
                    $hall = Hall::findOrFail($filterId);
                    $title = 'قاعة ' . $hall->name;
                    break;
                case 'lab':
                    $lab = Lab::findOrFail($filterId);
                    $title = 'معمل ' . $lab->name;
                    break;
                case 'department':
                    $department = Department::findOrFail($filterId);
                    $title = 'قسم ' . $department->nameAr;
                    break;
                case 'level':
                    $title = "المستوى الدراسي: {$filterId}";
                    break;
                case 'program_list':
                    $academic = Academic::find($filterId);
                    $title = $academic ? "اللائحة: {$academic->name_ar}" : "اللائحة {$filterId}";
                    break;
            }
        }
    
        $html = '
        <style>
            body { font-family: "Arial", "DejaVu Sans"; direction: rtl; }
            .header { width: 100%; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
            .logo-left { float: right; }
            .logo-right { float: left; }
            .title { text-align: center; font-size: 22px; font-weight: bold; flex-grow: 1; }
            .academic-year { 
                text-align: center; 
                font-size: 16px; 
                font-weight: bold; 
                margin-bottom: 10px;
                color: #333;
                border-bottom: 1px solid #ccc;
                padding-bottom: 5px;
            }
            table { border-collapse: collapse; width: 100%; margin-top: 20px; page-break-inside: avoid; }
            th, td { border: 1px solid #333; padding: 10px; text-align: center; vertical-align: top; }
            th { background-color: #f2f2f2; font-weight: bold; }
            .time-header { background-color: #e0e0e0; }
            .day-header { background-color: #d0d0d0; }
            .course-cell { min-height: 90px; display: flex; flex-direction: column; justify-content: center; }
        </style>
        ';
    
        $logoPath = storage_path('app/public/logo1.png');
        $logoPath2 = storage_path('app/public/logo55.png');
        $base64Logo1 = '';
        $base64Logo2 = '';
        
        if (file_exists($logoPath)) {
            $base64Logo1 = base64_encode(file_get_contents($logoPath));
        }
        
        if (file_exists($logoPath2)) {
            $base64Logo2 = base64_encode(file_get_contents($logoPath2));
        }
        
        $html .= '
        <div class="header">
            <div class="logo-right">
                <img src="data:image/png;base64,' . $base64Logo1 . '" style="height: 80px;">
            </div>
                    <div class="academic-year">
                للعام الجامعي 2025/2026 - الفصل الدراسي الأول
            </div>
            <div class="title">' . $title . '</div>
        </div>
        <div style="clear: both;"></div>';
    
        $html .= '<table><thead><tr><th class="day-header">اليوم / الوقت</th>';
        foreach ($timeSlots as $slot) {
            $html .= "<th class=\"time-header\">$slot</th>";
        }
        $html .= '</tr></thead><tbody>';
    
        $colorMap = [];
       // استبدال مصفوفة الألوان القديمة بهذه الألوان الزاهية
       $colors = [
        '#FF6B6B', // أحمر فاتح ناعم
        '#4ECDC4', // تركواز فاتح
        '#45B7D1', // أزرق سماوي
        '#FFA07A', // سلمون فاتح
        '#98D8C8', // أخضر مائي فاتح
        '#D4A5A5', // وردي ترابي
        '#B5EAD7', // أخضر نعناعي
        '#FFDAC1', // خوخي فاتح
        '#E2F0CB', // أخضر مصفر خفيف
        '#C7CEEA', // أزرق بنفسجي فاتح
        '#F8B195', // برتقالي وردي
        '#A8E6CF'  // أخضر مائي فاتح
    ];
        $colorIndex = 0;
    
        foreach ($days as $day) {
            $html .= "<tr><td class=\"day-header\"><strong>$day</strong></td>";
            foreach ($timeSlots as $slot) {
                $entry = $table[$day][$slot] ?? '';
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
                    $html .= "<td class=\"course-cell\" style='background-color: $bg;'>$cellContent</td>";
                }
            }
            $html .= '</tr>';
        }
    
        $html .= '</tbody></table>';
    
        $html .= '<div style="margin-top: 20px; font-size: 12px; text-align: center; color: #666;">';
        $html .= 'تم إنشاء الجدول في ' . now()->format('Y-m-d H:i') . ' | نظام جدولة المحاضرات';
        $html .= '</div>';
    
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'margin_top' => 15,
            'margin_bottom' => 15,
            'margin_left' => 10,
            'margin_right' => 10,
            'default_font' => 'dejavusans'
        ]);
    
        $mpdf->SetAutoPageBreak(true, 15);
        $mpdf->WriteHTML($html);
    
        $filename = 'schedule';
        if ($scheduleId) {
            $filename .= '_schedule_' . $scheduleId;
        } elseif ($filterType && $filterId) {
            $filename .= '_' . $filterType . '_' . $filterId;
        }
        $filename .= '.pdf';
    
        return response($mpdf->Output('', 'S'))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
    
    public function exportExcel($scheduleId = null, $filterType = null, $filterId = null)
    {
        $data = $this->buildTable($scheduleId, $filterType, $filterId, 'excel');
        $table = $data['table'];
        $timeSlots = $data['timeSlots'];
        $days = $data['days'];
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
    
        $title = 'جدول المحاضرات الأسبوعي';
        
        if ($scheduleId) {
            $schedule = Schedule::find($scheduleId);
            $title = $schedule ? $schedule->nameAr : 'الجدول ' . $scheduleId;
        } elseif ($filterType && $filterId) {
            switch ($filterType) {
                case 'lecturer':
                    $lecturer = Lecturer::findOrFail($filterId);
                    $title = $lecturer->academicDegree->prefix . ' ' . $lecturer->name;
                    break;
                case 'hall':
                    $hall = Hall::findOrFail($filterId);
                    $title = 'قاعة ' . $hall->name;
                    break;
                case 'lab':
                    $lab = Lab::findOrFail($filterId);
                    $title = 'معمل ' . $lab->name;
                    break;
                case 'department':
                    $department = Department::findOrFail($filterId);
                    $title = 'قسم ' . $department->name_ar;
                    break;
                case 'level':
                    $title = "المستوى الدراسي: {$filterId}";
                    break;
                case 'program_list':
                    $academic = Academic::find($filterId);
                    $title = $academic ? "اللائحة: {$academic->name_ar}" : "اللائحة {$filterId}";
                    break;
            }
        }
    
        $colCount = count($timeSlots) + 1;
        $lastCol = chr(ord('A') + $colCount - 1);
        $sheet->mergeCells('A1:' . $lastCol . '1');
        $sheet->setCellValue('A1', $title);
        
        $titleStyle = $sheet->getStyle('A1');
        $titleStyle->getFont()->setBold(true)->setSize(16);
        $titleStyle->getAlignment()->setHorizontal('center');
        $titleStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEBF7');
    
        $sheet->setCellValue('A2', 'اليوم / الوقت');
        $headerStyle = $sheet->getStyle('A2');
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2EFDA');
        $headerStyle->getAlignment()->setHorizontal('center');
        
        $colIndex = 1;
        foreach ($timeSlots as $slot) {
            $col = chr(ord('A') + $colIndex);
            $sheet->setCellValue($col . '2', $slot);
            $sheet->getStyle($col . '2')->getFont()->setBold(true);
            $sheet->getStyle($col . '2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2EFDA');
            $sheet->getStyle($col . '2')->getAlignment()->setHorizontal('center');
            $colIndex++;
        }
    
        $colorMap = [];
        $colors = [
            '#FF6B6B', // أحمر فاتح ناعم
            '#4ECDC4', // تركواز فاتح
            '#45B7D1', // أزرق سماوي
            '#FFA07A', // سلمون فاتح
            '#98D8C8', // أخضر مائي فاتح
            '#D4A5A5', // وردي ترابي
            '#B5EAD7', // أخضر نعناعي
            '#FFDAC1', // خوخي فاتح
            '#E2F0CB', // أخضر مصفر خفيف
            '#C7CEEA', // أزرق بنفسجي فاتح
            '#F8B195', // برتقالي وردي
            '#A8E6CF'  // أخضر مائي فاتح
        ];        $colorIndex = 0;
    
        $rowIndex = 3;
        foreach ($days as $day) {
            $sheet->setCellValue("A{$rowIndex}", $day);
            $sheet->getStyle("A{$rowIndex}")->getFont()->setBold(true);
            $sheet->getStyle("A{$rowIndex}")->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2CC');
            $sheet->getStyle("A{$rowIndex}")->getAlignment()->setHorizontal('center');
            
            $colIndex = 1;
            foreach ($timeSlots as $slot) {
                $col = chr(ord('A') + $colIndex);
                $entry = $table[$day][$slot] ?? '';
                if (!empty($entry)) {
                    $courseKey = $entry['course_key'] ?? '';
                    $cellContent = implode("\n\n", $entry['html']);
                    $cell = $col . $rowIndex;
    
                    $sheet->setCellValue($cell, $cellContent);
                    $sheet->getStyle($cell)
                        ->getAlignment()
                        ->setWrapText(true)
                        ->setVertical('center')
                        ->setHorizontal('center');
    
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
                $colIndex++;
            }
            $rowIndex++;
        }
    
        $footerRow = $rowIndex + 1;
        $sheet->mergeCells("A{$footerRow}:" . $lastCol . $footerRow);
        $sheet->setCellValue("A{$footerRow}", 'تم إنشاء الجدول في ' . now()->format('Y-m-d H:i') . ' | نظام جدولة المحاضرات');
        $sheet->getStyle("A{$footerRow}")->getFont()->setItalic(true);
        $sheet->getStyle("A{$footerRow}")->getAlignment()->setHorizontal('center');
    
        $lastRow = $rowIndex - 1;
        $tableRange = "A2:{$lastCol}{$lastRow}";
        $sheet->getStyle($tableRange)
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    
        $sheet->getColumnDimension('A')->setWidth(15);
        foreach (range('B', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setWidth(30);
        }
    
        for ($i = 3; $i <= $lastRow; $i++) {
            $sheet->getRowDimension($i)->setRowHeight(80);
        }
    
        $filename = 'schedule';
        if ($scheduleId) {
            $filename .= '_schedule_' . $scheduleId;
        } elseif ($filterType && $filterId) {
            $filename .= '_' . $filterType . '_' . $filterId;
        }
        $filename .= '.xlsx';
    
        $tempPath = tempnam(sys_get_temp_dir(), 'excel_');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);
    
        return response()->download($tempPath, $filename)->deleteFileAfterSend(true);
    }

    public function exportPdfSchedule($id)
    {
        return $this->exportPdf($id);
    }

    public function exportExcelSchedule($id)
    {
        return $this->exportExcel($id);
    }

    public function exportPdfLevel($level)
    {
        return $this->exportPdf(null, 'level', $level);
    }

    public function exportExcelLevel($level)
    {
        return $this->exportExcel(null, 'level', $level);
    }

    public function exportPdfProgram($id)
    {
        return $this->exportPdf(null, 'program_list', $id);
    }

    public function exportExcelProgram($id)
    {
        return $this->exportExcel(null, 'program_list', $id);
    }

    public function exportPdfLecturer($id)
    {
        return $this->exportPdf(null, 'lecturer', $id);
    }

    public function exportExcelLecturer($id)
    {
        return $this->exportExcel(null, 'lecturer', $id);
    }

    public function exportPdfHall($id)
    {
        return $this->exportPdf(null, 'hall', $id);
    }

    public function exportExcelHall($id)
    {
        return $this->exportExcel(null, 'hall', $id);
    }

    public function exportPdfLab($id)
    {
        return $this->exportPdf(null, 'lab', $id);
    }

    public function exportExcelLab($id)
    {
        return $this->exportExcel(null, 'lab', $id);
    }

    public function exportPdfDepartment($id)
    {
        return $this->exportPdf(null, 'department', $id);
    }

    public function exportExcelDepartment($id)
    {
        return $this->exportExcel(null, 'department', $id);
    }
}