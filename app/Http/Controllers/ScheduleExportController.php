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
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ScheduleExportController extends Controller
{
    private function loadData($filterType = null, $filterId = null)
    {
        $query = ScheduleEntry::with([
            'course',
            'lecturer.academicDegree',
            'hall',
            'lab',
            'department'
        ]);
    
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

            $staffName = $lecturerName ? $degreePrefix . ' ' . $lecturerName : '';
            
            $courseName = $entry->course->name_ar ?? $entry->course->name_en ?? '';
            
            $formattedData[] = [
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
            ];
        }
    
        return $formattedData;
    }

    private function buildTable($filterType = null, $filterId = null)
    {
        $sessions = $this->loadData($filterType, $filterId);
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
        for ($hour = 8; $hour <= 18; $hour += 2) {
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
        
            $teacher = $s['staff']['name'];
            
            $room = '';
            if ($s['hall']) {
                $room = 'قاعة: ' . $s['hall']['name'];
            } elseif ($s['room']) {
                $room = 'معمل: ' . $s['room']['name'];
            }
        
            $entryParts = [];
            if (!empty($name)) {
                $courseInfo = "<b>$name";
                if (!empty($code)) {
                    $courseInfo .= " ($code)";
                }
                $courseInfo .= "</b>";
                $entryParts[] = $courseInfo;
            }
            if (!empty($teacher)) {
                $entryParts[] =  $teacher;
            }
            if (!empty($room)) {
                $entryParts[] = $room;
            }
            
            $entry = implode('<br>', $entryParts);
        
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

    public function exportPdf($filterType = null, $filterId = null)
    {
        $data = $this->buildTable($filterType, $filterId);
        $table = $data['table'];
        $timeSlots = $data['timeSlots'];
        $days = $data['days'];
    
        // تحديد العنوان بناءً على نوع التصفية
        $title = 'جدول المحاضرات الأسبوعي'; // العنوان الافتراضي
        
        if ($filterType && $filterId) {
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
            }
        }
    
        $html = '
        <style>
            body { font-family: "Arial", "DejaVu Sans"; direction: rtl; }
            .header { width: 100%; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
            .logo-left { float: right; }
            .logo-right { float: left; }
            .title { text-align: center; font-size: 22px; font-weight: bold; flex-grow: 1; }
            table { border-collapse: collapse; width: 100%; margin-top: 40px; page-break-inside: avoid; }
            th, td { border: 1px solid #333; padding: 10px; text-align: center; vertical-align: top; }
            th { background-color: #f2f2f2; font-weight: bold; }
            .time-header { background-color: #e0e0e0; }
            .day-header { background-color: #d0d0d0; }
            .course-cell { min-height: 80px; }
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
            
            <div class="title">' . $title . '</div>
            
            <div class="logo-left">
                <img src="data:image/png;base64,' . $base64Logo2 . '" style="height: 80px;">
            </div>
        </div>
        <div style="clear: both;"></div>';
    
        $html .= '<table><thead><tr><th class="day-header">اليوم / الوقت</th>';
        foreach ($timeSlots as $slot) {
            $html .= "<th class=\"time-header\">$slot</th>";
        }
        $html .= '</tr></thead><tbody>';
    
        $colorMap = [];
        $colors = ['#e3f2fd', '#f1f8e9', '#ffecb3', '#fce4ec', '#e8f5e9', '#fff3e0', '#e0f7fa'];
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
        if ($filterType && $filterId) {
            $filename .= '_' . $filterType . '_' . $filterId;
        }
        $filename .= '.pdf';
    
        return response($mpdf->Output('', 'S'))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
    public function exportExcel($filterType = null, $filterId = null)
    {
        $data = $this->buildTable($filterType, $filterId);
        $table = $data['table'];
        $timeSlots = $data['timeSlots'];
        $days = $data['days'];
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
    
        // تحديد العنوان بناءً على نوع التصفية
        $title = 'جدول المحاضرات الأسبوعي'; // العنوان الافتراضي
        
        if ($filterType && $filterId) {
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
        $colors = ['e3f2fd', 'f1f8e9', 'ffecb3', 'fce4ec', 'e8f5e9', 'fff3e0', 'e0f7fa'];
        $colorIndex = 0;
    
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
                    $cellContent = implode("\n", $entry['html']);
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
        if ($filterType && $filterId) {
            $filename .= '_' . $filterType . '_' . $filterId;
        }
        $filename .= '.xlsx';
    
        $tempPath = tempnam(sys_get_temp_dir(), 'excel_');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);
    
        return response()->download($tempPath, $filename)->deleteFileAfterSend(true);
    }

    public function exportPdfLecturer($id)
    {
        return $this->exportPdf('lecturer', $id);
    }

    public function exportExcelLecturer($id)
    {
        return $this->exportExcel('lecturer', $id);
    }

    public function exportPdfHall($id)
    {
        return $this->exportPdf('hall', $id);
    }

    public function exportExcelHall($id)
    {
        return $this->exportExcel('hall', $id);
    }

    public function exportPdfLab($id)
    {
        return $this->exportPdf('lab', $id);
    }

    public function exportExcelLab($id)
    {
        return $this->exportExcel('lab', $id);
    }

    public function exportPdfDepartment($id)
    {
        return $this->exportPdf('department', $id);
    }

    public function exportExcelDepartment($id)
    {
        return $this->exportExcel('department', $id);
    }
}