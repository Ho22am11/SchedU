<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use Illuminate\Http\Request;
use Mpdf\Mpdf;

class ExportController extends Controller
{
    private function loadData($scheduleId)
{
    // جلب البيانات من قاعدة البيانات مع كل الـ relationships
    $schedule = Schedule::with([
        'entries.course',
        'entries.lecturer.academicDegree', 
        'entries.hall',
        'entries.lap', // لاحظ إنه lap مش lab
        'entries.department'
    ])->findOrFail($scheduleId);

    return $schedule->entries;
}

private function buildTable($scheduleId)
{
    $sessions = $this->loadData($scheduleId);
    $days = ['sunday' => 'الأحد', 'monday' => 'الاثنين', 'tuesday' => 'الثلاثاء', 'wednesday' => 'الأربعاء', 'thursday' => 'الخميس'];
    $timeSlots = ['09:00-11:00', '11:00-13:00', '13:00-15:00', '15:00-17:00'];

    $table = [];
    foreach ($timeSlots as $slot) {
        foreach ($days as $en => $ar) {
            $table[$slot][$ar] = '';
        }
    }

    foreach ($sessions as $s) {
        // بناء الـ time slot من البيانات
        $slot = "{$s->startTime}-{$s->endTime}";
        $day = strtolower($s->Day);
        $dayAr = $days[$day] ?? '';
    
        // استخدام البيانات من الـ relationships
        $name = $s->course->nameAr ?? $s->course->name;
        $type = $s->session_type; 
        $courseKey = $s->course->code; 
    
        // المحاضر
        $teacher = ($s->lecturer->academicDegree->prefix ?? '') . ' ' . $s->lecturer->name;
        
        // تحديد المكان (قاعة أو معمل)
        $room = '';
        if ($s->session_type === 'lecture' && $s->hall) {
            $room = $s->hall->name;
        } elseif ($s->session_type === 'lab' && $s->lap) {
            $room = $s->lap->name;
        }
    
        // بناء النص للخلية
        $entry = "$name<br>$teacher<br>قاعة $room";
        
        // إضافة معلومات المجموعة إذا كانت موجودة
        if ($s->group_number && $s->total_groups > 1) {
            $entry .= "<br>مجموعة {$s->group_number} من {$s->total_groups}";
        }
    
        // إضافة الـ entry للجدول
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

public function exportPdf($scheduleId)
{
    $table = $this->buildTable($scheduleId);
    $days = ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس'];
    $timeSlots = ['09:00-11:00', '11:00-13:00', '13:00-15:00', '15:00-17:00'];

    // جلب معلومات الجدول للعنوان
    $schedule = Schedule::findOrFail($scheduleId);
    $scheduleTitle = $schedule->nameAr ?? $schedule->nameEn ?? 'جدول المحاضرات الأسبوعي';

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
        .schedule-info { text-align: center; margin-bottom: 20px; font-size: 16px; }
    </style>
    ';

    // اللوجو
    $logoPath = storage_path('app/public/logo1.png');
    $base64Logo = '';
    if (file_exists($logoPath)) {
        $base64Logo = base64_encode(file_get_contents($logoPath));
    }
    
    $html .= '
    <div class="header">
        <div class="title">' . $scheduleTitle . '</div>
        <div class="logo">
            <img src="data:image/png;base64,' . $base64Logo . '" style="height: 70px;">
        </div>
        <div style="clear: both;"></div>
    </div>';

    // معلومات إضافية عن الجدول
    $totalSessions = $schedule->entries->count();
    $totalCourses = $schedule->entries->groupBy('course_id')->count();
    
    $html .= '
    <div class="schedule-info">
        <p>إجمالي الجلسات: ' . $totalSessions . ' | إجمالي المقررات: ' . $totalCourses . '</p>
    </div>';

    // بناء الجدول
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

    $mpdf->SetAutoPageBreak(true, 10);
    $mpdf->WriteHTML($html);

    $fileName = 'schedule_' . $schedule->id . '_' . date('Y-m-d') . '.pdf';

    return response($mpdf->Output('', 'S'))
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
}

// دالة مساعدة لعرض الجدول في المتصفح للمراجعة قبل التصدير
public function previewSchedule($scheduleId)
{
    $table = $this->buildTable($scheduleId);
    $schedule = Schedule::findOrFail($scheduleId);
    $days = ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس'];
    $timeSlots = ['09:00-11:00', '11:00-13:00', '13:00-15:00', '15:00-17:00'];

    return view('schedule.preview', compact('table', 'schedule', 'days', 'timeSlots'));
}
}
