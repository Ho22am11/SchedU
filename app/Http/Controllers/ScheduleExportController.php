<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExportFullScheduleRequest;
use App\Models\Academic;
use App\Models\Department;
use App\Models\Hall;
use App\Models\Lecturer;
use App\Models\Schedule;
use App\Models\ScheduleEntry;
use App\Services\ScheduleWorkbookExporter;
use Illuminate\Http\Request;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ScheduleExportController extends Controller
{
    // دالة لتحميل البيانات مع تطبيق الفلاتر
    private function loadFilteredData($scheduleId, $filters)
    {
        $query = ScheduleEntry::with([
            'lecturer.academicDegree' => function ($query) {
                $query->select('id', 'prefix', 'prefix_ar');
            },
            'lecturer' => function ($query) {
                $query->select('id', 'name', 'name_ar', 'department_id', 'academic_id');
            },
            'hall' => function ($query) {
                $query->select('id', 'name');
            },
            'lap' => function ($query) {
                $query->select('id', 'name');
            },
        ])
            ->where('schedule_id', $scheduleId)
            ->select(
                'id',
                'course_ids',
                'lecturer_id',
                'hall_id',
                'hall_name',
                'lap_id',
                'lap_name',
                'lecturer_name',
                'lecturer_name_ar',
                'session_type',
                'department_ids',
                'academic_ids',
                'academic_levels',
                'startTime',
                'endTime',
                'Day',
                'group_number',
                'total_groups'
            );

        // تطبيق الفلاتر
        if (! empty($filters['staff_id'])) {
            $query->where('lecturer_id', $filters['staff_id']);
        }
        if (! empty($filters['hall_id'])) {
            $query->where('hall_id', $filters['hall_id']);
        }
        if (! empty($filters['lab_id'])) {
            $query->where('lap_id', $filters['lab_id']);
        }
        if (! empty($filters['academic_list_id'])) {
            $query->whereJsonContains('academic_ids', $filters['academic_list_id']);
        }
        if (! empty($filters['academic_level'])) {
            $query->whereJsonContains('academic_levels', $filters['academic_level']);
        }
        if (! empty($filters['department_id'])) {
            $query->whereJsonContains('department_ids', $filters['department_id']);
        }

        $rows = $query->get()->toArray();

        // Point-in-time snapshots override the live names; the live relations
        // stay as the fallback for entries created before snapshots existed.
        foreach ($rows as &$row) {
            if ($row['hall_name'] !== null) {
                $row['hall'] = ['id' => $row['hall_id'], 'name' => $row['hall_name']];
            }
            if ($row['lap_name'] !== null) {
                $row['lap'] = ['id' => $row['lap_id'], 'name' => $row['lap_name']];
            }
            $row['lecturer'] = $row['lecturer'] ?? [];
            $row['lecturer']['name'] = $row['lecturer_name'] ?? $row['lecturer']['name'] ?? null;
            $row['lecturer']['name_ar'] = $row['lecturer_name_ar'] ?? $row['lecturer']['name_ar'] ?? null;
        }
        unset($row);

        return $rows;
    }

    /**
     * Rooms actually referenced by this schedule's entries — snapshot names
     * first, so per-room sheets survive room renames and deletions. Sorted
     * for stable sheet order.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function referencedRooms(array $entries, string $idColumn, string $snapshotColumn, string $relation): array
    {
        $rooms = [];
        foreach ($entries as $entry) {
            $id = $entry[$idColumn] ?? null;
            $name = $entry[$snapshotColumn] ?? $entry[$relation]['name'] ?? null;
            if ($id === null || $name === null) {
                continue;
            }
            $rooms[$id] = ['id' => (int) $id, 'name' => $name];
        }

        usort($rooms, fn (array $a, array $b): bool => strcasecmp($a['name'], $b['name']));

        return array_values($rooms);
    }

    // Remove duplicates from academic list, departments, levels, etc..
    private function getUniqueJoined($array, $key = null, $separator = ' / ')
    {
        if (empty($array)) {
            return '';
        }

        $values = $key ? array_map(function ($item) use ($key) {
            return $item[$key] ?? '';
        }, $array) : $array;

        $uniqueValues = array_unique(array_filter($values));

        return implode($separator, $uniqueValues);
    }

    // Course coloring function
    private function getCourseColor($code, &$colorMap)
    {
        if (isset($colorMap[$code])) {
            return $colorMap[$code];
        }

        // Better hash function with less collisions
        $hash = 0;
        for ($i = 0; $i < strlen($code); $i++) {
            $char = ord($code[$i]);
            $hash = (($hash << 5) - $hash) + $char;
            $hash = $hash & 0xFFFFFFFF; // Convert to 32bit integer
        }

        // Make hash positive and get hue
        $positiveHash = abs($hash);
        $hue = $positiveHash % 360;

        // Check if this hue is too close to existing colors
        $existingHues = [];
        foreach ($colorMap as $color) {
            if (preg_match('/hsl\((\d+),/', $color, $matches)) {
                $existingHues[] = (int) $matches[1];
            }
        }

        $finalHue = $hue;
        $attempts = 0;

        // Ensure minimum 30 degree separation from existing colors
        while ($attempts < 12) {
            $tooClose = false;
            foreach ($existingHues as $existingHue) {
                $diff = abs($finalHue - $existingHue);
                if ($diff < 30 || $diff > 330) { // Handle wrap-around
                    $tooClose = true;
                    break;
                }
            }

            if (! $tooClose) {
                break;
            }

            $finalHue = ($hue + ($attempts * 30)) % 360;
            $attempts++;
        }

        $color = "hsl({$finalHue}, 70%, 88%)";
        $colorMap[$code] = $color;

        return $color;
    }

    // Convert HSL to Hex for excel
    private function hslToHex($hsl, $includeHash = false)
    {
        // Extract hue, saturation, lightness from "hsl(240, 70%, 88%)"
        if (preg_match('/hsl\((\d+),\s*(\d+)%,\s*(\d+)%\)/', $hsl, $matches)) {
            $h = (int) $matches[1] / 360;
            $s = (int) $matches[2] / 100;
            $l = (int) $matches[3] / 100;

            if ($s == 0) {
                $r = $g = $b = $l;
            } else {
                $hue2rgb = function ($p, $q, $t) {
                    if ($t < 0) {
                        $t += 1;
                    }
                    if ($t > 1) {
                        $t -= 1;
                    }
                    if ($t < 1 / 6) {
                        return $p + ($q - $p) * 6 * $t;
                    }
                    if ($t < 1 / 2) {
                        return $q;
                    }
                    if ($t < 2 / 3) {
                        return $p + ($q - $p) * (2 / 3 - $t) * 6;
                    }

                    return $p;
                };

                $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
                $p = 2 * $l - $q;
                $r = $hue2rgb($p, $q, $h + 1 / 3);
                $g = $hue2rgb($p, $q, $h);
                $b = $hue2rgb($p, $q, $h - 1 / 3);
            }

            $hex = sprintf('%02X%02X%02X', round($r * 255), round($g * 255), round($b * 255));

            return $includeHash ? '#'.$hex : $hex;
        }

        return $includeHash ? '#FFFFFF' : 'FFFFFF'; // Default white
    }

    // Generate descriptive file names
    private function generateFileName($scheduleId, $filters, $extension)
    {
        // Get schedule name
        $schedule = Schedule::select('nameEn', 'nameAr')->find($scheduleId);
        $scheduleName = $schedule ? ($schedule->nameEn ?? $schedule->nameAr ?? 'schedule') : 'schedule';

        // Clean the schedule name for filename
        $cleanScheduleName = $this->cleanForFilename($scheduleName);

        $filenameParts = [$cleanScheduleName];

        // Add filter information
        $filterParts = [];

        // Staff filter
        if (! empty($filters['staff_id'])) {
            $staff = \App\Models\Lecturer::with('academicDegree')->find($filters['staff_id']);
            if ($staff) {
                $prefix = '';
                if ($staff->academicDegree) {
                    $prefix = ($staff->academicDegree->prefix ?? '').'_';
                }
                $staffName = $this->cleanForFilename($prefix.($staff->name ?? 'staff'));
                $filterParts[] = 'staff_'.$staffName;
            }
        }

        // Hall filter
        if (! empty($filters['hall_id'])) {
            $hall = \App\Models\Hall::find($filters['hall_id']);
            if ($hall) {
                $filterParts[] = 'hall_'.$this->cleanForFilename($hall->name);
            }
        }

        // Lab filter
        if (! empty($filters['lab_id'])) {
            $lab = \App\Models\Lap::find($filters['lab_id']);
            if ($lab) {
                $filterParts[] = 'lab_'.$this->cleanForFilename($lab->name);
            }
        }

        // Academic list filter
        if (! empty($filters['academic_list_id'])) {
            $academic = \App\Models\Academic::find($filters['academic_list_id']);
            if ($academic) {
                $filterParts[] = 'academic_'.$this->cleanForFilename($academic->name ?? 'academic');
            }
        }

        // Academic level filter
        if (! empty($filters['academic_level'])) {
            $filterParts[] = 'level_'.$filters['academic_level'];
        }

        // Department filter
        if (! empty($filters['department_id'])) {
            $department = \App\Models\Department::find($filters['department_id']);
            if ($department) {
                $filterParts[] = 'dept_'.$this->cleanForFilename($department->name ?? 'department');
            }
        }

        // Add filters to filename if any exist
        if (! empty($filterParts)) {
            $filenameParts[] = implode('_', $filterParts);
        }

        // Add timestamp and extension
        $timestamp = date('Y-m-d_H-i-s');
        $filename = implode('_', $filenameParts).'_'.$timestamp.'.'.$extension;

        return $filename;
    }

    private function cleanForFilename($string)
    {
        // Remove or replace characters that are not safe for filenames
        $string = trim($string);
        // Replace Arabic and special characters with safe equivalents or remove them
        $string = preg_replace('/[^\w\-_\.]/', '_', $string);
        // Remove multiple underscores
        $string = preg_replace('/_+/', '_', $string);
        // Remove leading/trailing underscores
        $string = trim($string, '_');

        // Limit length
        return substr($string, 0, 50);
    }

    // Build header string according to filters
    private function buildFilterHeaderString($filters)
    {
        $filterParts = [];

        // Staff filter
        if (! empty($filters['staff_id'])) {
            $staff = \App\Models\Lecturer::with('academicDegree')->find($filters['staff_id']);
            if ($staff) {
                $prefix = '';
                if ($staff->academicDegree) {
                    $prefix = ($staff->academicDegree->prefix_ar ?? $staff->academicDegree->prefix ?? '').' ';
                }
                $staffName = $prefix.($staff->name_ar ?? $staff->name);
                $filterParts[] = 'العضو: '.trim($staffName);
            }
        }

        // Hall filter
        if (! empty($filters['hall_id'])) {
            $hall = \App\Models\Hall::find($filters['hall_id']);
            if ($hall) {
                $filterParts[] = 'القاعة: '.$hall->name;
            }
        }

        // Lab filter
        if (! empty($filters['lab_id'])) {
            $lab = \App\Models\Lap::find($filters['lab_id']);
            if ($lab) {
                $filterParts[] = 'المعمل: '.$lab->name;
            }
        }

        // Academic list filter
        if (! empty($filters['academic_list_id'])) {
            $academic = \App\Models\Academic::find($filters['academic_list_id']);
            if ($academic) {
                $filterParts[] = 'القائمة الأكاديمية: '.($academic->name_ar ?? $academic->name);
            }
        }

        // Academic level filter
        if (! empty($filters['academic_level'])) {
            $filterParts[] = 'المستوى: '.$filters['academic_level'];
        }

        // Department filter
        if (! empty($filters['department_id'])) {
            $department = \App\Models\Department::find($filters['department_id']);
            if ($department) {
                $filterParts[] = 'القسم: '.($department->name_ar ?? $department->name);
            }
        }

        return empty($filterParts) ? '' : implode(' - ', $filterParts);
    }

    // Calculate the data needed for the metadata sub table
    private function calculateMetadata($entries)
    {
        $uniqueCourses = [];
        $uniqueRooms = [];
        $uniqueStaff = [];

        foreach ($entries as $entry) {
            // Count unique courses
            if (! empty($entry['course_ids']) && is_array($entry['course_ids'])) {
                foreach ($entry['course_ids'] as $courseId) {
                    $uniqueCourses[$courseId] = true;
                }
            }

            // Count unique rooms (halls and labs)
            if (! empty($entry['hall']['id'])) {
                $uniqueRooms['hall_'.$entry['hall']['id']] = true;
            }
            if (! empty($entry['lap']['id'])) {
                $uniqueRooms['lab_'.$entry['lap']['id']] = true;
            }

            // Count unique staff
            if (! empty($entry['lecturer']['id'])) {
                $uniqueStaff[$entry['lecturer']['id']] = true;
            }
        }

        return [
            'total_sessions' => count($entries),
            'total_courses' => count($uniqueCourses),
            'total_rooms' => count($uniqueRooms),
            'total_staff' => count($uniqueStaff),
        ];
    }

    // دالة لبناء الجدول من البيانات
    private function buildTable($entries, $exportType = 'pdf', ?array $reservedPeriod = null)
    {
        $daysMap = [
            'saturday' => 'السبت',
            'sunday' => 'الأحد',
            'monday' => 'الاثنين',
            'tuesday' => 'الثلاثاء',
            'wednesday' => 'الأربعاء',
            'thursday' => 'الخميس',
            'friday' => 'الجمعة',
        ];

        // إنشاء time slots مسبقا
        $timeSlots = array_map(function ($hour) {
            $start = str_pad($hour, 2, '0', STR_PAD_LEFT).':00';
            $end = str_pad($hour + 2, 2, '0', STR_PAD_LEFT).':00';

            return $start.'-'.$end;
        }, range(9, 18, 2));

        // تهيئة الجدول باستخدام array_fill
        $table = array_fill_keys(
            array_values($daysMap),
            array_fill_keys($timeSlots, '')
        );

        foreach ($entries as $entry) {
            $dayAr = $daysMap[strtolower($entry['Day'])] ?? '';
            if (empty($dayAr)) {
                continue;
            }

            $slot = ($entry['startTime'] ? substr($entry['startTime'], 0, 5) : '').'-'.
                ($entry['endTime'] ? substr($entry['endTime'], 0, 5) : '');

            // تجميع بيانات الخلية
            $entryContent = $this->buildCellContent($entry, $exportType);

            if (! empty($entryContent)) {
                $courseKey = '';
                if (! empty($entry['course_ids']) && is_array($entry['course_ids'])) {
                    $courseModels = \App\Models\Course::whereIn('id', $entry['course_ids'])->get();
                    $courseKey = $courseModels->pluck('code')->implode('-');
                }

                if (empty($table[$dayAr][$slot])) {
                    $table[$dayAr][$slot] = [
                        'html' => [$entryContent],
                        'course_key' => $courseKey,
                    ];
                } else {
                    // التعديل هنا: استبدال الخط بكلمة "أو"
                    if ($exportType === 'pdf') {
                        $separator = '<div style="text-align:center; font-weight:bold; margin:5px 0;">________________________</br></div>';
                    } else {
                        $separator = "\n────────────\n";
                    }

                    $table[$dayAr][$slot]['html'][] = $separator.$entryContent;
                }
            }
        }

        if ($reservedPeriod === null) {
            return [
                'table' => $table,
                'timeSlots' => $timeSlots,
                'days' => array_values($daysMap),
            ];
        }

        $reservedDay = $daysMap[$reservedPeriod['day']] ?? null;
        $reservedSlot = $reservedPeriod['start_time'].'-'.$reservedPeriod['end_time'];

        if ($reservedDay && isset($table[$reservedDay][$reservedSlot])) {
            $table[$reservedDay][$reservedSlot] = [
                'html' => [$reservedPeriod['label_ar']],
                'is_reserved' => true,
            ];
        }

        return [
            'table' => $table,
            'timeSlots' => $timeSlots,
            'days' => array_values($daysMap),
        ];
    }

    // دالة مساعدة لبناء محتوى الخلية
    private function buildCellContent($entry, $exportType)
    {
        $parts = [];
        $esc = function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };

        // 1. Course names + Course codes (first line)
        $uniqueCourseNames = '';
        $uniqueCourseCodes = '';

        if (! empty($entry['course_ids']) && is_array($entry['course_ids'])) {
            $courseModels = \App\Models\Course::whereIn('id', $entry['course_ids'])->get();
            $uniqueCourseNames = $this->getUniqueJoined($courseModels->toArray(), 'name_ar');
            $uniqueCourseCodes = $this->getUniqueJoined($courseModels->toArray(), 'code');
        }

        if ($uniqueCourseNames && $uniqueCourseCodes) {
            $courseInfo = "{$uniqueCourseNames} ({$uniqueCourseCodes})";
            if ($exportType === 'pdf') {
                $courseInfo = "<div style='font-weight:bold; font-size:14px;'>{$courseInfo}</div>";
            }
            $parts[] = $courseInfo;
        }

        // 2. Academic levels (second line)
        if (! empty($entry['academic_levels']) && is_array($entry['academic_levels'])) {
            $uniqueLevels = $this->getUniqueJoined($entry['academic_levels'], null, ' - ');
            if ($uniqueLevels) {
                $levelInfo = "المستوى {$uniqueLevels}";
                if ($exportType === 'pdf') {
                    $levelInfo = "<div style='font-size:12px; font-weight:bold;'>{$levelInfo}</div>";
                }
                $parts[] = $levelInfo;
            }
        }

        // 3. Academic lists (third line)
        if (! empty($entry['academic_ids']) && is_array($entry['academic_ids'])) {
            $academicModels = \App\Models\Academic::whereIn('id', $entry['academic_ids'])->get();
            $uniqueAcademics = $this->getUniqueJoined($academicModels->toArray(), 'name_ar');
            if ($uniqueAcademics) {
                $academicInfo = $uniqueAcademics;
                if ($exportType === 'pdf') {
                    $academicInfo = "<div style='font-size:12px; font-weight:bold;'>{$academicInfo}</div>";
                }
                $parts[] = $academicInfo;
            }
        }

        // 4. Room info (fourth line)
        $hallName = $esc($entry['hall']['name'] ?? '');
        $lapName = $esc($entry['lap']['name'] ?? '');
        $room = '';
        if (! empty($hallName)) {
            $room = 'قاعة: '.$hallName;
        } elseif (! empty($lapName)) {
            $room = 'معمل: '.$lapName;
        }

        if (! empty($room)) {
            $roomDisplay = $exportType === 'pdf'
                ? "<div style='font-weight:bold;font-size:12px;'>{$room}</div>"
                : $room;
            $parts[] = $roomDisplay;
        }

        // 5. Group info (fifth line) - only show if more than 1 group
        if (isset($entry['group_number']) && isset($entry['total_groups']) && $entry['total_groups'] > 1) {
            $group = "المجموعة {$esc($entry['group_number'])} / {$esc($entry['total_groups'])}";
            if ($exportType === 'pdf') {
                $group = "<div style='font-size:12px;'>{$group}</div>";
            }
            $parts[] = $group;
        }

        // 6. Staff name with degree prefix (sixth line)
        $degreePrefix = $esc($entry['lecturer']['academic_degree']['prefix_ar'] ?? $entry['lecturer']['academic_degree']['prefix'] ?? '');
        $lecturerName = $esc($entry['lecturer']['name_ar'] ?? $entry['lecturer']['name'] ?? '');
        $staffName = trim($degreePrefix.' '.$lecturerName);

        if (! empty($staffName)) {
            $staffDisplay = $exportType === 'pdf'
                ? "<div style='font-weight:bold;font-size:12px;'>{$staffName}</div>"
                : "{$staffName}";
            $parts[] = $staffDisplay;
        }

        // Return with proper line breaks
        return $exportType === 'pdf' ? implode('', $parts) : implode("\n", $parts);
    }

    // تصدير PDF مع الفلاتر
    public function exportPdfWithFilters(Request $request)
    {
        set_time_limit(300);

        $validated = $request->validate([
            'schedule_id' => 'required|exists:schedules,id',
        ]);

        $scheduleId = $validated['schedule_id'];
        $filters = $request->only([
            'staff_id',
            'hall_id',
            'lab_id',
            'academic_list_id',
            'academic_level',
            'department_id',
        ]);

        $entries = $this->loadFilteredData($scheduleId, $filters);
        $reservedPeriod = Schedule::findOrFail($scheduleId)->reservedPeriod();
        $data = $this->buildTable(
            $entries,
            'pdf',
            $reservedPeriod
        );

        // Build filter string
        $filterString = $this->buildFilterHeaderString($filters);

        $schedule = Schedule::select('nameAr')->find($scheduleId);
        $title = $schedule ? $schedule->nameAr : 'الجدول '.$scheduleId;

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'margin_top' => 15,
            'margin_bottom' => 15,
            'margin_left' => 10,
            'margin_right' => 10,
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ]);

        $mpdf->SetAutoPageBreak(true, 15);
        $mpdf->WriteHTML($this->generatePdfHtml(
            $title,
            $data['table'],
            $data['timeSlots'],
            $data['days'],
            $filterString,
            $entries,
            $this->signatureTitlesFor(! empty($filters['staff_id']))
        ));

        $filename = $this->generateFileName($scheduleId, $filters, 'pdf');

        return response($mpdf->Output('', 'S'))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }

    // تصدير Excel مع الفلاتر
    public function exportExcelWithFilters(Request $request, ScheduleWorkbookExporter $workbookExporter)
    {
        set_time_limit(300);

        $validated = $request->validate([
            'schedule_id' => 'required|exists:schedules,id',
        ]);

        $scheduleId = $validated['schedule_id'];
        $filters = $request->only([
            'staff_id',
            'hall_id',
            'lab_id',
            'academic_list_id',
            'academic_level',
            'department_id',
        ]);

        $entries = $this->loadFilteredData($scheduleId, $filters);
        $reservedPeriod = Schedule::findOrFail($scheduleId)->reservedPeriod();
        $data = $this->buildTable(
            $entries,
            'excel',
            $reservedPeriod
        );

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);

        $schedule = Schedule::select('nameAr')->find($scheduleId);
        $title = $schedule ? $schedule->nameAr : 'الجدول '.$scheduleId;

        // Build filter string
        $filterString = $this->buildFilterHeaderString($filters);

        $colCount = count($data['timeSlots']) + 1;
        $lastCol = chr(65 + $colCount - 1);

        // Main title
        $sheet->mergeCells('A1:'.$lastCol.'1');
        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16],
            'alignment' => ['horizontal' => 'center'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']],
        ]);

        // Filter header (if filters exist)
        $headerRow = 2;
        if (! empty($filterString)) {
            $sheet->mergeCells('A2:'.$lastCol.'2');
            $sheet->setCellValue('A2', $filterString);
            $sheet->getStyle('A2')->applyFromArray([
                'font' => ['bold' => true, 'size' => 12],
                'alignment' => ['horizontal' => 'center'],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F4FD']],
            ]);
            $headerRow = 3; // Move table headers down
        }

        // Table headers
        $sheet->setCellValue('A'.$headerRow, 'اليوم / الوقت');
        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2EFDA']],
            'alignment' => ['horizontal' => 'center'],
        ];

        $sheet->getStyle('A'.$headerRow)->applyFromArray($headerStyle);

        $colIndex = 1;
        foreach ($data['timeSlots'] as $slot) {
            $col = chr(65 + $colIndex);
            $sheet->setCellValue($col.$headerRow, $slot);
            $sheet->getStyle($col.$headerRow)->applyFromArray($headerStyle);
            $colIndex++;
        }

        // Fill data with coloring
        $colorMap = [];
        $rowIndex = $headerRow + 1; // Start after headers

        foreach ($data['days'] as $day) {
            $sheet->setCellValue("A{$rowIndex}", $day);
            $sheet->getStyle("A{$rowIndex}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF2CC']],
                'alignment' => ['horizontal' => 'center'],
            ]);

            $colIndex = 1;
            foreach ($data['timeSlots'] as $slot) {
                $col = chr(65 + $colIndex);
                $cell = $col.$rowIndex;
                $entry = $data['table'][$day][$slot] ?? '';

                if (! empty($entry)) {
                    $sheet->setCellValue($cell, implode("\n", $entry['html']));
                    $sheet->getStyle($cell)->getAlignment()
                        ->setWrapText(true)
                        ->setVertical('center')
                        ->setHorizontal('center');

                    if (! empty($entry['is_reserved'])) {
                        $sheet->getStyle($cell)->getFill()
                            ->setFillType(Fill::FILL_SOLID)
                            ->getStartColor()
                            ->setRGB('E7E5E4');
                        $sheet->getStyle($cell)->getFont()->setBold(true);
                    } else {
                        $courseKey = $entry['course_key'] ?? '';
                        if (! empty($courseKey)) {
                            $color = $this->getCourseColor($courseKey, $colorMap);
                            $rgbColor = $this->hslToHex($color, false); // Excel needs hex without #

                            $sheet->getStyle($cell)->getFill()
                                ->setFillType(Fill::FILL_SOLID)
                                ->getStartColor()
                                ->setRGB($rgbColor);
                        }
                    }
                }
                $colIndex++;
            }
            $rowIndex++;
        }

        // Add metadata table after main schedule table
        $metadataStartRow = $rowIndex + 2; // Leave one empty row
        $metadata = $this->calculateMetadata($entries);

        // Metadata title
        $sheet->mergeCells('A'.$metadataStartRow.':D'.$metadataStartRow);
        $sheet->setCellValue('A'.$metadataStartRow, 'إحصائيات الجدول');
        $sheet->getStyle('A'.$metadataStartRow)->applyFromArray([
            'font' => ['bold' => true, 'size' => 14],
            'alignment' => ['horizontal' => 'center'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']],
        ]);

        // Metadata headers
        $metadataHeaderRow = $metadataStartRow + 1;
        $metadataHeaders = ['إجمالي الجلسات', 'إجمالي المقررات', 'إجمالي الغرف', 'إجمالي أعضاء هيئة التدريس'];
        $metadataValues = [$metadata['total_sessions'], $metadata['total_courses'], $metadata['total_rooms'], $metadata['total_staff']];

        for ($i = 0; $i < 4; $i++) {
            $col = chr(65 + $i); // A, B, C, D

            // Header
            $sheet->setCellValue($col.$metadataHeaderRow, $metadataHeaders[$i]);
            $sheet->getStyle($col.$metadataHeaderRow)->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2EFDA']],
                'alignment' => ['horizontal' => 'center'],
            ]);

            // Value
            $sheet->setCellValue($col.($metadataHeaderRow + 1), $metadataValues[$i]);
            $sheet->getStyle($col.($metadataHeaderRow + 1))->applyFromArray([
                'font' => ['bold' => true],
                'alignment' => ['horizontal' => 'center'],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']],
            ]);
        }

        // Metadata table borders
        $metadataTableRange = "A{$metadataStartRow}:D".($metadataHeaderRow + 1);
        $sheet->getStyle($metadataTableRange)->getBorders()
            ->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // Set column widths for metadata
        foreach (['A', 'B', 'C', 'D'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(25);
        }

        // Signature block under the table (one row, right to left)
        $workbookExporter->addSignatures(
            $sheet,
            $this->signatureTitlesFor(! empty($filters['staff_id'])),
            $metadataHeaderRow + 3,
            $colCount
        );

        // Table formatting
        $tableRange = "A{$headerRow}:{$lastCol}".($rowIndex - 1);
        $sheet->getStyle($tableRange)->getBorders()
            ->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // Column and row dimensions
        $sheet->getColumnDimension('A')->setWidth(20);
        foreach (range('B', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setWidth(45);
        }

        for ($i = $headerRow + 1; $i < $rowIndex; $i++) {
            $sheet->getRowDimension($i)->setRowHeight(120);
        }

        // Data cell alignment
        $dataRange = 'B'.($headerRow + 1).":{$lastCol}".($rowIndex - 1);
        $sheet->getStyle($dataRange)->getAlignment()
            ->setWrapText(true)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $filename = $this->generateFileName($scheduleId, $filters, 'xlsx');
        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        return response($content)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }

    public function exportFullExcel(
        ExportFullScheduleRequest $request,
        ScheduleWorkbookExporter $workbookExporter
    ) {
        set_time_limit(300);

        $validated = $request->validated();
        $scheduleId = $validated['schedule_id'];
        $sections = $validated['sections'];
        $schedule = Schedule::select([
            'nameAr',
            'nameEn',
            'reserved_period_day',
            'reserved_period_start_time',
            'reserved_period_end_time',
            'reserved_period_label_ar',
        ])->findOrFail($scheduleId);
        $reservedPeriod = $schedule->reservedPeriod();
        $entries = $this->loadFilteredData($scheduleId, []);
        $spreadsheet = new Spreadsheet;
        $colorMap = [];

        $this->addFullWorkbookSheet(
            $workbookExporter,
            $spreadsheet,
            'الجدول الرئيسي',
            $schedule->nameAr ?? $schedule->nameEn ?? 'الجدول',
            '',
            $entries,
            $reservedPeriod,
            $colorMap
        );

        foreach ($sections as $section) {
            if ($section === 'halls') {
                foreach ($this->referencedRooms($entries, 'hall_id', 'hall_name', 'hall') as $hall) {
                    $filteredEntries = array_values(array_filter(
                        $entries,
                        fn (array $entry): bool => (int) ($entry['hall_id'] ?? 0) === $hall['id']
                    ));
                    $this->addFullWorkbookSheet(
                        $workbookExporter,
                        $spreadsheet,
                        'قاعة - '.$hall['name'],
                        $schedule->nameAr ?? $schedule->nameEn ?? 'الجدول',
                        'القاعة: '.$hall['name'],
                        $filteredEntries,
                        $reservedPeriod,
                        $colorMap
                    );
                }
            }

            if ($section === 'labs') {
                foreach ($this->referencedRooms($entries, 'lap_id', 'lap_name', 'lap') as $lab) {
                    $filteredEntries = array_values(array_filter(
                        $entries,
                        fn (array $entry): bool => (int) ($entry['lap_id'] ?? 0) === $lab['id']
                    ));
                    $this->addFullWorkbookSheet(
                        $workbookExporter,
                        $spreadsheet,
                        'معمل - '.$lab['name'],
                        $schedule->nameAr ?? $schedule->nameEn ?? 'الجدول',
                        'المعمل: '.$lab['name'],
                        $filteredEntries,
                        $reservedPeriod,
                        $colorMap
                    );
                }
            }

            if ($section === 'lecturers') {
                $this->addStaffCategorySheets(
                    $workbookExporter,
                    $spreadsheet,
                    $schedule->nameAr ?? $schedule->nameEn ?? 'الجدول',
                    $entries,
                    $reservedPeriod,
                    $colorMap,
                    ['professor', 'associate professor', 'assistant professor'],
                    'عضو هيئة تدريس'
                );
            }

            if ($section === 'teaching_assistants') {
                $this->addStaffCategorySheets(
                    $workbookExporter,
                    $spreadsheet,
                    $schedule->nameAr ?? $schedule->nameEn ?? 'الجدول',
                    $entries,
                    $reservedPeriod,
                    $colorMap,
                    ['assistant lecturer', 'teaching assistant'],
                    'هيئة معاونة'
                );
            }

            if ($section === 'academic_lists') {
                $this->addAcademicListLevelSheets(
                    $workbookExporter,
                    $spreadsheet,
                    $schedule->nameAr ?? $schedule->nameEn ?? 'الجدول',
                    $entries,
                    $reservedPeriod,
                    $colorMap
                );
            }
        }

        $filename = $this->generateFullExportFilename($schedule);
        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        return response($content)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }

    private function addAcademicListLevelSheets(
        ScheduleWorkbookExporter $workbookExporter,
        Spreadsheet $spreadsheet,
        string $scheduleTitle,
        array $entries,
        ?array $reservedPeriod,
        array &$colorMap
    ): void {
        $pairs = [];
        foreach ($entries as $entry) {
            foreach ($entry['academic_ids'] ?? [] as $academicId) {
                foreach ($entry['academic_levels'] ?? [] as $academicLevel) {
                    $pairs[(int) $academicId.':'.(int) $academicLevel] = [
                        'academic_id' => (int) $academicId,
                        'academic_level' => (int) $academicLevel,
                    ];
                }
            }
        }

        $academics = Academic::whereIn(
            'id',
            array_unique(array_column($pairs, 'academic_id'))
        )->get(['id', 'name', 'name_ar'])->keyBy('id');

        usort($pairs, function (array $left, array $right) use ($academics): int {
            $leftName = $academics[$left['academic_id']]->name_ar
                ?? $academics[$left['academic_id']]->name
                ?? '';
            $rightName = $academics[$right['academic_id']]->name_ar
                ?? $academics[$right['academic_id']]->name
                ?? '';

            return [$leftName, $left['academic_level']] <=> [$rightName, $right['academic_level']];
        });

        foreach ($pairs as $pair) {
            $academic = $academics->get($pair['academic_id']);
            if (! $academic) {
                continue;
            }

            $academicName = $academic->name_ar ?? $academic->name;
            $filteredEntries = array_values(array_filter($entries, function (array $entry) use ($pair): bool {
                return in_array($pair['academic_id'], $entry['academic_ids'] ?? [], true)
                    && in_array($pair['academic_level'], $entry['academic_levels'] ?? [], true);
            }));

            $this->addFullWorkbookSheet(
                $workbookExporter,
                $spreadsheet,
                'لائحة - '.$academicName.' - L'.$pair['academic_level'],
                $scheduleTitle,
                'القائمة الأكاديمية: '.$academicName.' - المستوى: '.$pair['academic_level'],
                $filteredEntries,
                $reservedPeriod,
                $colorMap
            );
        }
    }

    private function addStaffCategorySheets(
        ScheduleWorkbookExporter $workbookExporter,
        Spreadsheet $spreadsheet,
        string $scheduleTitle,
        array $entries,
        ?array $reservedPeriod,
        array &$colorMap,
        array $degreeNames,
        string $categoryLabel
    ): void {
        $members = Lecturer::with('academicDegree:id,name,prefix,prefix_ar')
            ->whereHas('academicDegree', function ($query) use ($degreeNames) {
                $query->whereIn('name', $degreeNames);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'name_ar', 'academic_id']);

        foreach ($members as $member) {
            $degree = $member->academicDegree;
            $prefix = $degree?->prefix_ar
                ?? $degree?->prefix
                ?? '';
            $memberName = trim($prefix.' '.($member->name_ar ?? $member->name));
            $filteredEntries = array_values(array_filter(
                $entries,
                fn (array $entry): bool => (int) ($entry['lecturer_id'] ?? 0) === $member->id
            ));
            $this->addFullWorkbookSheet(
                $workbookExporter,
                $spreadsheet,
                $categoryLabel.' - '.$memberName,
                $scheduleTitle,
                $categoryLabel.': '.$memberName,
                $filteredEntries,
                $reservedPeriod,
                $colorMap,
                true
            );
        }
    }

    private function addFullWorkbookSheet(
        ScheduleWorkbookExporter $workbookExporter,
        Spreadsheet $spreadsheet,
        string $worksheetTitle,
        string $scheduleTitle,
        string $filterString,
        array $entries,
        ?array $reservedPeriod,
        array &$colorMap,
        bool $isMemberSchedule = false
    ): void {
        $data = $this->buildTable($entries, 'excel', $reservedPeriod);
        $workbookExporter->addWorksheet(
            $spreadsheet,
            $worksheetTitle,
            $scheduleTitle,
            $filterString,
            $data,
            $entries,
            $colorMap,
            $this->signatureTitlesFor($isMemberSchedule)
        );
    }

    // عناوين خانات التوقيع: جدول العضو (معلم أو محاضر) يبدأ برئيس القسم، وغير ذلك بمنسق الجداول
    private function signatureTitlesFor(bool $forMember): array
    {
        return $forMember
            ? ['رئيس القسم', 'وكيل الكلية', 'عميد الكلية']
            : ['منسق الجداول', 'وكيل الكلية', 'عميد الكلية'];
    }

    private function generateFullExportFilename(Schedule $schedule): string
    {
        $scheduleName = $schedule->nameEn ?? $schedule->nameAr ?? 'schedule';
        $cleanScheduleName = $this->cleanForFilename($scheduleName) ?: 'schedule';

        return $cleanScheduleName.'_full_'.date('Y-m-d_H-i-s').'.xlsx';
    }

    // دالة مساعدة لإنشاء HTML لـ PDF
    private function generatePdfHtml($title, $table, $timeSlots, $days, $filterString = '', $entries = [], $signatureTitles = [])
    {
        $colorMap = [];

        $html = '<!DOCTYPE html><html><head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <style>
            body { font-family: "Arial", "DejaVu Sans"; direction: rtl; }
            .header { margin-bottom: 10px; text-align: center; }
            .title { font-size: 22px; font-weight: bold; }
            .filters { font-size: 14px; font-weight: bold; margin-top: 5px; color: #555; }
            table { border-collapse: collapse; width: 100%; margin-top: 20px; }
            th, td { border: 1px solid #333; padding: 8px; text-align: center; vertical-align: middle; }
            th { background-color: #f2f2f2; }
            .time-header { background-color: #e0e0e0; }
            .day-header { background-color: #d0d0d0; }
            .entry-content { font-size: 12px; }
        </style>
    </head><body>';

        $html .= '<div class="header">
        <div class="title">'.htmlspecialchars($title).'</div>
        <div style="font-size: 16px; margin-top: 5px;">للعام الجامعي '.date('Y').'/'.(date('Y') + 1).' - الفصل الدراسي الأول</div>';

        // Add filter string if exists
        if (! empty($filterString)) {
            $html .= '<div class="filters">'.htmlspecialchars($filterString).'</div>';
        }

        $html .= '</div>';

        // Rest of the table generation remains the same...
        $html .= '<table><thead><tr><th class="day-header">اليوم / الوقت</th>';
        foreach ($timeSlots as $slot) {
            $html .= '<th class="time-header">'.htmlspecialchars($slot).'</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($days as $day) {
            $html .= '<tr><td class="day-header"><strong>'.htmlspecialchars($day).'</strong></td>';

            foreach ($timeSlots as $slot) {
                $entry = $table[$day][$slot] ?? '';
                if (empty($entry)) {
                    $html .= '<td></td>';

                    continue;
                }

                $backgroundColor = '#f0f0f0'; // Default color

                if (! empty($entry['is_reserved'])) {
                    $backgroundColor = '#e7e5e4';
                } elseif (! empty($entry['course_key'])) {
                    $courseKey = $entry['course_key'];
                    $hslColor = $this->getCourseColor($courseKey, $colorMap);
                    $backgroundColor = $this->hslToHex($hslColor, true); // PDF needs hex with #
                }

                $cellContent = '<div class="entry-content">'.implode('', $entry['html']).'</div>';
                $html .= '<td style="background-color:'.$backgroundColor.';">'.$cellContent.'</td>';
            }

            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '<div style="text-align:center; margin-top:20px; font-size:12px;">
        تم إنشاء الجدول في '.date('Y-m-d H:i').' | نظام جدولة المحاضرات
    </div>';
        // Add metadata table before closing
        $metadata = $this->calculateMetadata($entries);

        $html .= '<div style="margin-top: 30px;">
    <table style="width: 60%; margin: 0 auto;">
        <thead>
            <tr>
                <th colspan="4" style="background-color: #DDEBF7; font-size: 16px; padding: 10px;">إحصائيات الجدول</th>
            </tr>
            <tr style="background-color: #E2EFDA;">
                <th style="padding: 8px;">إجمالي الجلسات</th>
                <th style="padding: 8px;">إجمالي المقررات</th>
                <th style="padding: 8px;">إجمالي الغرف</th>
                <th style="padding: 8px;">إجمالي أعضاء هيئة التدريس</th>
            </tr>
        </thead>
        <tbody>
            <tr style="background-color: #FFFFFF;">
                <td style="text-align: center; padding: 8px; font-weight: bold;">'.$metadata['total_sessions'].'</td>
                <td style="text-align: center; padding: 8px; font-weight: bold;">'.$metadata['total_courses'].'</td>
                <td style="text-align: center; padding: 8px; font-weight: bold;">'.$metadata['total_rooms'].'</td>
                <td style="text-align: center; padding: 8px; font-weight: bold;">'.$metadata['total_staff'].'</td>
            </tr>
        </tbody>
    </table>
</div>';

        // خانات التوقيع: صف واحد من اليمين إلى اليسار، عنوان كل مسؤول ومساحة فارغة تحته
        if (! empty($signatureTitles)) {
            $signatureCells = '';
            foreach ($signatureTitles as $signatureTitle) {
                $signatureCells .= '<td dir="rtl" style="border: none; width: 33%; text-align: center; vertical-align: top; padding: 10px 20px 70px 20px;">'
                    .'<div style="font-weight: bold; font-size: 14px;">'.htmlspecialchars($signatureTitle).'</div>'
                    .'</td>';
            }
            $html .= '<table dir="rtl" style="width: 100%; margin-top: 30px; page-break-inside: avoid;"><tr>'.$signatureCells.'</tr></table>';
        }

        $html .= '</body></html>';

        return $html;
    }

    // دالة مساعدة للحصول على الشعارات
    private function getBase64Logo($filename)
    {
        $path = storage_path('app/public/'.$filename);
        if (file_exists($path)) {
            $mime = mime_content_type($path);
            $data = base64_encode(file_get_contents($path));

            return "data:{$mime};base64,{$data}";
        }

        return '';
    }
}
