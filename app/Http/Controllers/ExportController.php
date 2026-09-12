<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use Illuminate\Http\Request;
use Mpdf\Mpdf;

class ExportController extends Controller
{
    private function loadData($scheduleId)
    {
        // The old 'course'/'department' relations were removed when schedule
        // entries moved to multiple-ID JSON columns — loading them throws.
        // Courses/academics/departments render through the model accessors.
        $schedule = Schedule::with([
            'entries.lecturer.academicDegree',
            'entries.hall',
            'entries.lap',
            'entries.externalCourse',
        ])->findOrFail($scheduleId);

        if ($schedule->entries->isEmpty()) {
            \Log::warning('No entries found for schedule ID: '.$scheduleId);
        }

        return $schedule->entries;
    }

    private function buildTable($scheduleId, string $locale)
    {

        $sessions = $this->loadData($scheduleId);
        $days = [
            'saturday' => 'السبت',
            'sunday' => 'الأحد',
            'monday' => 'الاثنين',
            'tuesday' => 'الثلاثاء',
            'wednesday' => 'الأربعاء',
            'thursday' => 'الخميس',
            'friday' => 'الجمعة',
        ];
        $timeSlots = ['09:00-11:00', '11:00-13:00', '13:00-15:00', '15:00-17:00', '17:00-19:00'];

        $table = [];
        foreach ($timeSlots as $slot) {
            foreach ($days as $en => $ar) {
                $table[$slot][$ar] = '';
            }
        }

        foreach ($sessions as $s) {
            $start = substr($s->startTime, 0, 5);
            $end = substr($s->endTime, 0, 5);

            // Bucket by the containing 2h slot so packed 1h sessions share
            // their parent cell instead of matching no exact key.
            $slot = '';
            foreach ($timeSlots as $timeSlot) {
                [$slotStart, $slotEnd] = explode('-', $timeSlot);
                if ($start !== '' && $start >= $slotStart && $end <= $slotEnd) {
                    $slot = $timeSlot;
                    break;
                }
            }
            if ($slot === '') {
                $slot = "{$start}-{$end}";
            }

            $dayEn = strtolower($s->Day);
            $dayAr = $days[$dayEn] ?? null;

            if (empty($dayAr)) {
                \Log::warning('Day not found in mapping:', ['day' => $s->Day]);

                continue;
            }

            $academic_name = '';
            if ($s->academic) {
                $academic_name = $locale === 'en' ? $s->academic->name : $s->academic->name_ar;

            }

            // External entries render from their baked snapshot (the course's
            // fields as they were at write time — survives edits and
            // deletions); the live relation is the legacy fallback. Local
            // entries fall back to the first course of course_ids (the old
            // single course relation is gone).
            if (($s->entry_kind ?? 'course') === 'external') {
                $code = $s->external_course_code ?? $s->externalCourse?->code;
                $nameEn = $s->external_course_name_en ?? $s->externalCourse?->name_en;
                $nameAr = $s->external_course_name_ar ?? $s->externalCourse?->name_ar;
                if ($locale === 'en') {
                    $name = 'External — '.($nameEn ?? '').($code ? ' ('.$code.' )' : '');
                } else {
                    $name = '( '.$code.' )  '.($nameAr ?? '');
                }
                $courseKey = 'external_'.$s->external_course_id;
            } else {
                $firstCourse = $s->courses->first();
                if ($firstCourse) {
                    $name = $locale === 'en'
                        ? $firstCourse->name_en.' ('.$firstCourse->code.' )'
                        : '( '.$firstCourse->code.' )  '.$firstCourse->name_ar;
                    $courseKey = $firstCourse->code;
                } else {
                    $name = '';
                    $courseKey = '';
                }
            }

            $type = $s->session_type ?? 'unknown';

            $teacher = 'غير محدد';
            if ($s->lecturer) {
                $prefix = $locale === 'en' ? $s->lecturer->academicDegree->prefix : $s->lecturer->academicDegree->prefix_ar;
                $teacher = $locale === 'en' ? $prefix.' '.$s->lecturer->name : $prefix.' '.$s->lecturer->name_ar;
            }

            $room = 'غير محدد';
            // Snapshot names take precedence so past exports survive room renames
            // and deletions; live relations are the legacy fallback.
            $hallName = $s->hall_name ?? $s->hall?->name;
            $lapName = $s->lap_name ?? $s->lap?->name;
            if ($s->session_type === 'lecture' && $hallName) {
                $room = $locale === 'en' ? 'hall'.' '.$hallName : 'قاعه'.' '.$hallName;
            } elseif ($s->session_type === 'lab' && $lapName) {
                $room = $locale === 'en' ? 'lab'.' '.$lapName : 'معمل'.' '.$lapName;
            } elseif (($s->entry_kind ?? 'course') === 'external') {
                $room = $locale === 'en' ? 'External venue' : 'قاعة خارجية';
            }

            $entry = "$academic_name<br>$name<br>$teacher<br> $room<br>";

            if ($s->group_number && $s->total_groups > 1) {
                $entry .= "<br>مجموعة {$s->group_number} من {$s->total_groups}";
            }

            if (empty($table[$slot][$dayAr])) {
                $table[$slot][$dayAr] = [
                    'html' => [$entry],
                    'course_key' => $courseKey,
                    'type' => $type,
                ];
            } else {
                $table[$slot][$dayAr]['html'][] = $entry;
            }
        }

        $reservedPeriod = Schedule::findOrFail($scheduleId)->reservedPeriod();
        if ($reservedPeriod === null) {
            return $table;
        }

        $reservedDay = $days[$reservedPeriod['day']] ?? null;
        $reservedSlot = $reservedPeriod['start_time'].'-'.$reservedPeriod['end_time'];

        if ($reservedDay && isset($table[$reservedSlot][$reservedDay])) {
            $table[$reservedSlot][$reservedDay] = [
                'html' => [$reservedPeriod['label_ar']],
                'is_reserved' => true,
            ];
        }

        return $table;
    }

    public function exportPdf(Request $request, $scheduleId)
    {

        $locale = $request->header('Accept-Language', 'en');

        app()->setLocale($locale);

        $table = $this->buildTable($scheduleId, $locale);
        $days = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'];
        $timeSlots = ['09:00-11:00', '11:00-13:00', '13:00-15:00', '15:00-17:00', '17:00-19:00'];

        $schedule = Schedule::findOrFail($scheduleId);
        $scheduleTitle = $schedule->nameAr ?? $schedule->nameEn ?? 'جدول المحاضرات الأسبوعي';

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

        $logoPath = storage_path('app/public/logo1.png');
        $base64Logo = '';
        if (file_exists($logoPath)) {
            $base64Logo = base64_encode(file_get_contents($logoPath));
        }

        $html .= '
    <div class="header">
        <div class="title">'.$scheduleTitle.'</div>
        <div class="logo">
            <img src="data:image/png;base64,'.$base64Logo.'" style="height: 70px;">
        </div>
        <div style="clear: both;"></div>
    </div>';

        $totalSessions = $schedule->entries->count();
        // Unique local courses across course_ids plus external courses.
        $totalCourses = $schedule->entries
            ->flatMap(fn ($entry) => $entry->course_ids ?? [])
            ->unique()
            ->count()
            + $schedule->entries->pluck('external_course_id')->filter()->unique()->count();

        $html .= '
    <div class="schedule-info">
        <p>إجمالي الجلسات: '.$totalSessions.' | إجمالي المقررات: '.$totalCourses.'</p>
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
                    if (! empty($entry['is_reserved'])) {
                        $bg = '#e7e5e4';
                    } else {
                        $courseKey = $entry['course_key'] ?? '';
                        if (! isset($colorMap[$courseKey])) {
                            $colorMap[$courseKey] = $colors[$colorIndex % count($colors)];
                            $colorIndex++;
                        }
                        $bg = $colorMap[$courseKey];
                    }
                    $cellContent = implode('<hr>', $entry['html']);
                    $html .= "<td style='background-color: $bg;'>$cellContent</td>";
                }
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

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

        $fileName = 'schedule_'.$schedule->id.'_'.date('Y-m-d').'.pdf';

        return response($mpdf->Output('', 'S'))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$fileName.'"');
    }
}
