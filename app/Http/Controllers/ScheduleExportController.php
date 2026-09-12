<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExportFullScheduleRequest;
use App\Models\Academic;
use App\Models\Department;
use App\Models\Hall;
use App\Models\Lecturer;
use App\Models\Schedule;
use App\Models\ScheduleBlocker;
use App\Models\ScheduleEntry;
use App\Services\ScheduleWorkbookExporter;
use Illuminate\Http\Request;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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
            'externalCourse' => function ($query) {
                $query->select(
                    'id',
                    'code',
                    'name_en',
                    'name_ar',
                    'requesting_entity_en',
                    'requesting_entity_ar',
                    'lecture_venue'
                );
            },
        ])
            ->where('schedule_id', $scheduleId)
            ->select(
                'id',
                'entry_kind',
                'external_course_id',
                'external_course_code',
                'external_course_name_en',
                'external_course_name_ar',
                'external_course_entity_en',
                'external_course_entity_ar',
                'external_course_venue',
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

            // Baked external-course identity: reconstruct the summary from
            // the snapshots so exports survive course edits and deletions.
            if (($row['entry_kind'] ?? 'course') === 'external') {
                $hasSnapshot = $row['external_course_name_en'] !== null
                    || $row['external_course_name_ar'] !== null;
                if ($hasSnapshot) {
                    $row['external_course'] = [
                        'id' => $row['external_course_id'],
                        'code' => $row['external_course_code'],
                        'name_en' => $row['external_course_name_en'],
                        'name_ar' => $row['external_course_name_ar'],
                        'requesting_entity_en' => $row['external_course_entity_en'],
                        'requesting_entity_ar' => $row['external_course_entity_ar'],
                        'lecture_venue' => $row['external_course_venue'],
                    ];
                }
            }
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
                $filterParts[] = 'اللائحة الأكاديمية: '.($academic->name_ar ?? $academic->name);
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

    // دالة لبناء الجدول من البيانات
    private function buildTable($entries, $exportType = 'pdf', ?array $reservedPeriod = null, array $staffBlockers = [])
    {
        // الجمعة مستبعدة من التصدير كلياً: أي كتل أو حجوزات أو فترة محجوزة
        // تقع فيها تُتجاهل.
        $daysMap = [
            'saturday' => 'السبت',
            'sunday' => 'الأحد',
            'monday' => 'الاثنين',
            'tuesday' => 'الثلاثاء',
            'wednesday' => 'الأربعاء',
            'thursday' => 'الخميس',
        ];

        // إنشاء time slots مسبقا
        $timeSlots = array_map(function ($hour) {
            $start = str_pad($hour, 2, '0', STR_PAD_LEFT).':00';
            $end = str_pad($hour + 2, 2, '0', STR_PAD_LEFT).':00';

            return $start.'-'.$end;
        }, range(9, 18, 2));

        // تهيئة الجدول: كل خلية (يوم، فترة) تحمل قائمة كتل — كل كتلة في خلية
        // مستقلة تحتها، واليوم يتمدد على عدة صفوف في العرض.
        $table = array_fill_keys(
            array_values($daysMap),
            array_fill_keys($timeSlots, [])
        );

        foreach ($entries as $entry) {
            $dayAr = $daysMap[strtolower($entry['Day'])] ?? '';
            if (empty($dayAr)) {
                continue;
            }

            $entryStart = $entry['startTime'] ? substr($entry['startTime'], 0, 5) : '';
            $entryEnd = $entry['endTime'] ? substr($entry['endTime'], 0, 5) : '';

            // Bucket by the 2h grid slot CONTAINING the session: a 1h session
            // (two groups packed back-to-back in one slot) shares its parent
            // slot — the historical exact-key match would leave it cellless
            // and silently drop it from the export.
            $slot = '';
            foreach ($timeSlots as $timeSlot) {
                [$slotStart, $slotEnd] = explode('-', $timeSlot);
                if ($entryStart !== '' && $entryStart >= $slotStart && $entryEnd <= $slotEnd) {
                    $slot = $timeSlot;
                    break;
                }
            }
            if ($slot === '') {
                $slot = $entryStart.'-'.$entryEnd;
            }

            // تجميع بيانات الخلية
            $entryContent = $this->buildCellContent($entry, $exportType);

            if (! empty($entryContent)) {
                // A session shorter than its cell carries its own span so two
                // packed groups in one cell stay distinguishable.
                if ($entryStart !== '' && $entryEnd !== '' && (strtotime($entryEnd) - strtotime($entryStart)) < 7200) {
                    $spanLabel = $entryStart.'-'.$entryEnd;
                    $entryContent = ($exportType === 'pdf'
                            ? "<div style='font-size:11px; color:#444;'>{$spanLabel}</div>"
                            : "[{$spanLabel}]\n")
                        .$entryContent;
                }
                $courseKey = '';
                if (($entry['entry_kind'] ?? 'course') === 'external') {
                    // External courses colour by their own identity — their
                    // course_ids array is always empty.
                    $courseKey = 'external_'.($entry['external_course_id'] ?? '');
                } elseif (! empty($entry['course_ids']) && is_array($entry['course_ids'])) {
                    $courseModels = \App\Models\Course::whereIn('id', $entry['course_ids'])->get();
                    $courseKey = $courseModels->pluck('code')->implode('-');
                }

                $table[$dayAr][$slot][] = [
                    'html' => [$entryContent],
                    'course_key' => $courseKey,
                ];
            }
        }

        // Blockers of the filtered staff member fill EMPTY cells only — a
        // real session always wins, since the blocker is enforced at
        // generation time and never erases history. Unlike the reserved
        // period below, they never overwrite existing content.
        foreach ($staffBlockers as $blocker) {
            $blockerDay = $daysMap[strtolower($blocker['day'])] ?? null;
            $blockerSlot = $blocker['startTime'].'-'.$blocker['endTime'];

            if ($blockerDay === null || ! isset($table[$blockerDay][$blockerSlot]) || $table[$blockerDay][$blockerSlot] !== []) {
                continue;
            }

            $label = (string) ($blocker['label'] ?? '');
            $table[$blockerDay][$blockerSlot][] = [
                'html' => [$exportType === 'pdf'
                    ? "<div style='font-weight:bold; font-size:13px;'>".htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</div>'
                    : $label],
                'is_blocker' => true,
            ];
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
            // الفترة المحجوزة تحل محل أي كتل أخرى في الخلية.
            $table[$reservedDay][$reservedSlot] = [[
                'html' => [$reservedPeriod['label_ar']],
                'is_reserved' => true,
            ]];
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

        // External entries render from their own course row — never from
        // local courses, academics, departments, or a room they don't have.
        if (($entry['entry_kind'] ?? 'course') === 'external') {
            return $this->buildExternalCellContent($entry, $exportType, $esc);
        }

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

    /**
     * External cell: `خارجي — {name} ({code})`, the requesting entity, the
     * component, the lab/hall — or "قاعة خارجية" (external venue) when the
     * outside faculty hosts the lecture — and the staff, or an explicit
     * no-internal-staff line.
     */
    private function buildExternalCellContent($entry, $exportType, $esc)
    {
        $course = $entry['external_course'] ?? [];
        $parts = [];

        $name = $esc($course['name_ar'] ?? $course['name_en'] ?? '');
        $code = (! empty($course['code']))
            ? ' ('.$esc($course['code']).')'
            : '';
        $title = "خارجي — {$name}{$code}";
        $parts[] = $exportType === 'pdf'
            ? "<div style='font-weight:bold; font-size:14px;'>{$title}</div>"
            : $title;

        $entity = $esc($course['requesting_entity_ar'] ?? $course['requesting_entity_en'] ?? '');
        if ($entity !== '') {
            $entityLine = "الجهة الطالبة: {$entity}";
            $parts[] = $exportType === 'pdf'
                ? "<div style='font-size:12px;'>{$entityLine}</div>"
                : $entityLine;
        }

        // 3. Component + room: labs book a lab, lectures a hall, and an
        // external-venue lecture books no room of ours at all.
        $hallName = $esc($entry['hall']['name'] ?? '');
        $lapName = $esc($entry['lap']['name'] ?? '');
        $room = '';
        if (! empty($hallName)) {
            $room = 'قاعة: '.$hallName;
        } elseif (! empty($lapName)) {
            $room = 'معمل: '.$lapName;
        } elseif (($entry['session_type'] ?? '') === 'lab') {
            $room = 'معمل';
        } else {
            $room = 'قاعة خارجية';
        }

        $roomDisplay = $exportType === 'pdf'
            ? "<div style='font-weight:bold;font-size:12px;'>{$room}</div>"
            : $room;
        $parts[] = $roomDisplay;

        // 4. Group info - only show if more than 1 group
        if (isset($entry['group_number']) && isset($entry['total_groups']) && $entry['total_groups'] > 1) {
            $group = "المجموعة {$esc($entry['group_number'])} / {$esc($entry['total_groups'])}";
            $parts[] = $exportType === 'pdf'
                ? "<div style='font-size:12px;'>{$group}</div>"
                : $group;
        }

        // 5. Staff name with degree prefix, or an explicit none-assigned line
        $degreePrefix = $esc($entry['lecturer']['academic_degree']['prefix_ar'] ?? $entry['lecturer']['academic_degree']['prefix'] ?? '');
        $lecturerName = $esc($entry['lecturer']['name_ar'] ?? $entry['lecturer']['name'] ?? '');
        $staffName = trim($degreePrefix.' '.$lecturerName);

        if ($staffName !== '') {
            $staffDisplay = $exportType === 'pdf'
                ? "<div style='font-weight:bold;font-size:12px;'>{$staffName}</div>"
                : "{$staffName}";
            $parts[] = $staffDisplay;
        } else {
            $staffDisplay = 'بدون عضو هيئة تدريس داخلي';
            $parts[] = $exportType === 'pdf'
                ? "<div style='font-style:italic;font-size:12px;'>{$staffDisplay}</div>"
                : $staffDisplay;
        }

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
            $reservedPeriod,
            $this->scheduleBlockersFor($scheduleId, $filters['staff_id'] ?? null)
        );

        // Build filter string
        $filterString = $this->buildFilterHeaderString($filters);
        $isMemberSchedule = ! empty($filters['staff_id']);
        if ($isMemberSchedule) {
            $filterString .= ($filterString === '' ? '' : ' - ').$this->hoursLabel($entries);
        }

        $schedule = Schedule::select('nameAr')->find($scheduleId);
        $title = $schedule ? $schedule->nameAr : 'الجدول '.$scheduleId;

        $memberDepartmentId = $this->memberDepartmentId($filters['staff_id'] ?? null);

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
            $this->signatureRowsFor($isMemberSchedule, $memberDepartmentId)
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
            $reservedPeriod,
            $this->scheduleBlockersFor($scheduleId, $filters['staff_id'] ?? null)
        );

        // Build filter string
        $filterString = $this->buildFilterHeaderString($filters);
        $isMemberSchedule = ! empty($filters['staff_id']);
        if ($isMemberSchedule) {
            $filterString .= ($filterString === '' ? '' : ' - ').$this->hoursLabel($entries);
        }

        $schedule = Schedule::select('nameAr')->find($scheduleId);
        $title = $schedule ? $schedule->nameAr : 'الجدول '.$scheduleId;

        // Same sheet layout as the bulk workbook — one renderer for both paths.
        $spreadsheet = new Spreadsheet;
        $colorMap = [];
        $workbookExporter->addWorksheet(
            $spreadsheet,
            $title,
            $title,
            $filterString,
            $data,
            $colorMap,
            $this->signatureRowsFor($isMemberSchedule, $this->memberDepartmentId($filters['staff_id'] ?? null))
        );

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
        $blockersByStaff = $this->scheduleBlockersByStaff($scheduleId);
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
                    'عضو هيئة تدريس',
                    $blockersByStaff
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
                    'هيئة معاونة',
                    $blockersByStaff
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
        // academic_ids و academic_levels متوازيان بالفهرس (كل قائمة بمستواها،
        // والتكرار مقصود لتعدد الدفعات الحاضرة) — الضرب الديكارتي بينهما يولّد
        // دفعات وهمية (قائمة بمستوى لا تدرسه) وأوراقاً فارغة، لذا نضامهما.
        $pairs = [];
        foreach ($entries as $entry) {
            $academicIds = $entry['academic_ids'] ?? [];
            $academicLevels = $entry['academic_levels'] ?? [];
            foreach ($academicIds as $index => $academicId) {
                $academicLevel = $academicLevels[$index] ?? null;
                if ($academicLevel === null) {
                    continue;
                }
                $pairs[(int) $academicId.':'.(int) $academicLevel] = [
                    'academic_id' => (int) $academicId,
                    'academic_level' => (int) $academicLevel,
                ];
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
                // نفس محاذاة الفهرس عند التصفية: القائمة i بمستواها i فقط.
                foreach ($entry['academic_ids'] ?? [] as $index => $academicId) {
                    if ((int) $academicId === $pair['academic_id']
                        && (int) ($entry['academic_levels'][$index] ?? PHP_INT_MIN) === $pair['academic_level']) {
                        return true;
                    }
                }

                return false;
            }));

            $this->addFullWorkbookSheet(
                $workbookExporter,
                $spreadsheet,
                'لائحة - '.$academicName.' - L'.$pair['academic_level'],
                $scheduleTitle,
                'اللائحة الأكاديمية: '.$academicName.' - المستوى: '.$pair['academic_level'],
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
        string $categoryLabel,
        array $blockersByStaff = []
    ): void {
        $members = Lecturer::with('academicDegree:id,name,prefix,prefix_ar')
            ->whereHas('academicDegree', function ($query) use ($degreeNames) {
                $query->whereIn('name', $degreeNames);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'name_ar', 'academic_id', 'department_id']);

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
                $categoryLabel.': '.$memberName.' - '.$this->hoursLabel($filteredEntries),
                $filteredEntries,
                $reservedPeriod,
                $colorMap,
                true,
                $blockersByStaff[$member->id] ?? [],
                $member->department_id === null ? null : (int) $member->department_id
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
        bool $isMemberSchedule = false,
        array $staffBlockers = [],
        ?int $departmentId = null
    ): void {
        $data = $this->buildTable($entries, 'excel', $reservedPeriod, $staffBlockers);
        $workbookExporter->addWorksheet(
            $spreadsheet,
            $worksheetTitle,
            $scheduleTitle,
            $filterString,
            $data,
            $colorMap,
            $this->signatureRowsFor($isMemberSchedule, $departmentId)
        );
    }

    /**
     * صفوف التوقيع: العنوان ثم مساحة توقيع فارغة ثم اسم المسؤول. جدول العضو
     * (معلم أو محاضر) يبدأ برئيس القسم — واسمه يتحدد بقسم العضو — وغير ذلك
     * بمنسق الجداول.
     *
     * @return array<int, array{title: string, name: string}>
     */
    private function signatureRowsFor(bool $forMember, ?int $departmentId = null): array
    {
        $departmentHead = $this->isComputerScienceDepartment($departmentId)
            ? 'أ.د. جمال محمد بحيري'
            : 'أ.د. وائل عبدالقادر عوض';

        return $forMember
            ? [
                ['title' => 'رئيس القسم', 'name' => $departmentHead],
                ['title' => 'وكيل الكلية', 'name' => 'أ.د. سامي محمد دراز'],
                ['title' => 'عميد الكلية', 'name' => 'أ.د. وائل عبدالقادر عوض'],
            ]
            : [
                ['title' => 'منسق الجداول', 'name' => 'د. أحمد محمد ربيع'],
                ['title' => 'وكيل الكلية', 'name' => 'أ.د. سامي محمد دراز'],
                ['title' => 'عميد الكلية', 'name' => 'أ.د. وائل عبدالقادر عوض'],
            ];
    }

    private function memberDepartmentId($staffId): ?int
    {
        if (empty($staffId)) {
            return null;
        }

        $departmentId = Lecturer::query()->whereKey($staffId)->value('department_id');

        return $departmentId === null ? null : (int) $departmentId;
    }

    // قسم علوم الحاسب يُطابق بالاسم لا بالمعرف، فالمعرفات تختلف بين البيئات.
    private function isComputerScienceDepartment(?int $departmentId): bool
    {
        if ($departmentId === null) {
            return false;
        }

        static $csDepartmentId = null;
        if ($csDepartmentId === null) {
            $csDepartmentId = (int) (Department::query()
                ->whereRaw('lower(name) = ?', ['computer science'])
                ->value('id') ?? 0);
        }

        return $csDepartmentId > 0 && $departmentId === $csDepartmentId;
    }

    /**
     * عدد الساعات المعتمدة للعضو: كل كتلة تُحسب بطولها الفعلي (كتلة ساعتان
     * = ساعتان، كتلة ساعة = ساعة).
     */
    private function teachingHours(array $entries): int
    {
        $seconds = 0;
        foreach ($entries as $entry) {
            if (empty($entry['startTime']) || empty($entry['endTime'])) {
                continue;
            }
            $seconds += strtotime($entry['endTime']) - strtotime($entry['startTime']);
        }

        return (int) round($seconds / 3600);
    }

    private function hoursLabel(array $entries): string
    {
        return 'عدد الساعات : '.$this->teachingHours($entries).' ساعة';
    }

    /**
     * Blockers are read from the schedule's baked set, never live from the
     * staff templates — a staff-page edit never rewrites a generated
     * schedule's exports (same point-in-time policy as the entry name
     * snapshots).
     */
    private function scheduleBlockersFor($scheduleId, $staffId): array
    {
        if (empty($staffId)) {
            return [];
        }

        return ScheduleBlocker::query()
            ->where('schedule_id', $scheduleId)
            ->where('lecturer_id', $staffId)
            ->get(['day', 'startTime', 'endTime', 'label'])
            ->toArray();
    }

    /**
     * The schedule's baked blockers grouped by lecturer_id for the bulk
     * workbook, where each per-member sheet renders only its owner's.
     *
     * @return array<int, array<int, array{day: string, startTime: string, endTime: string, label: string}>>
     */
    private function scheduleBlockersByStaff($scheduleId): array
    {
        $grouped = [];

        foreach (ScheduleBlocker::query()
            ->where('schedule_id', $scheduleId)
            ->get(['lecturer_id', 'day', 'startTime', 'endTime', 'label']) as $blocker) {
            $grouped[$blocker->lecturer_id][] = $blocker->only(['day', 'startTime', 'endTime', 'label']);
        }

        return $grouped;
    }

    private function generateFullExportFilename(Schedule $schedule): string
    {
        $scheduleName = $schedule->nameEn ?? $schedule->nameAr ?? 'schedule';
        $cleanScheduleName = $this->cleanForFilename($scheduleName) ?: 'schedule';

        return $cleanScheduleName.'_full_'.date('Y-m-d_H-i-s').'.xlsx';
    }

    // دالة مساعدة لإنشاء HTML لـ PDF
    private function generatePdfHtml($title, $table, $timeSlots, $days, $filterString = '', $signatureRows = [])
    {
        $colorMap = [];

        $html = '<!DOCTYPE html><html><head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <style>
            body { font-family: "Arial", "DejaVu Sans"; direction: rtl; }
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

        // الترويسة: شعار الجامعة يميناً وشعار الكلية يساراً والعنوان بينهما
        // (الجدول يميني، فالخلية الأولى تُعرض على يمين الصفحة).
        $universityLogo = $this->getBase64Logo('du-logo.png');
        $facultyLogo = $this->getBase64Logo('cai-logo.png');

        $html .= '<table dir="rtl" style="width:100%; border:none; margin-top:0;"><tr>'
            .'<td style="border:none; width:18%; text-align:center; vertical-align:middle;">'
            .($universityLogo !== '' ? '<img src="'.$universityLogo.'" style="height:70px;" />' : '')
            .'</td>'
            .'<td style="border:none; width:64%; text-align:center; vertical-align:middle;">'
            .'<div class="title">'.htmlspecialchars($title).'</div>'
            .'<div style="font-size: 16px; margin-top: 5px;">للعام الجامعي '.date('Y').'/'.(date('Y') + 1).' - الفصل الدراسي الأول</div>';

        if (! empty($filterString)) {
            $html .= '<div class="filters">'.htmlspecialchars($filterString).'</div>';
        }

        $html .= '</td>'
            .'<td style="border:none; width:18%; text-align:center; vertical-align:middle;">'
            .($facultyLogo !== '' ? '<img src="'.$facultyLogo.'" style="height:85px;" />' : '')
            .'</td>'
            .'</tr></table>';

        $html .= '<table><thead><tr><th class="day-header">اليوم / الوقت</th>';
        foreach ($timeSlots as $slot) {
            $html .= '<th class="time-header">'.htmlspecialchars($slot).'</th>';
        }
        $html .= '</tr></thead><tbody>';

        // كل كتلة في خلية مستقلة تحت بعضها، واليوم يتمدد بـ rowspan على كل صفوفه.
        foreach ($days as $day) {
            $dayRowCount = 1;
            foreach ($timeSlots as $slot) {
                $dayRowCount = max($dayRowCount, count($table[$day][$slot] ?? []));
            }

            for ($blockIndex = 0; $blockIndex < $dayRowCount; $blockIndex++) {
                $html .= '<tr>';

                if ($blockIndex === 0) {
                    $dayRowspan = $dayRowCount > 1 ? ' rowspan="'.$dayRowCount.'"' : '';
                    $html .= '<td class="day-header"'.$dayRowspan.'><strong>'.htmlspecialchars($day).'</strong></td>';
                }

                foreach ($timeSlots as $slot) {
                    $blocks = $table[$day][$slot] ?? [];

                    // فترة بلا كتل: خلية فارغة ممدودة على ارتفاع اليوم كاملاً
                    // (نظير دمج الخلايا الفارغة في إكسل) حتى لا تظهر ثقوب
                    // بلا إطار في الشبكة.
                    if ($blocks === []) {
                        if ($blockIndex === 0) {
                            $emptyRowspan = $dayRowCount > 1 ? ' rowspan="'.$dayRowCount.'"' : '';
                            $html .= '<td'.$emptyRowspan.' style="background-color:#f0f0f0;"></td>';
                        }

                        continue;
                    }

                    if ($blockIndex >= count($blocks)) {
                        continue; // خلية ممدودة من صف سابق
                    }

                    $block = $blocks[$blockIndex];
                    $rowspan = 1;
                    if (count($blocks) === 1 && $dayRowCount > 1) {
                        $rowspan = $dayRowCount;
                    } elseif (count($blocks) > 1 && $blockIndex === count($blocks) - 1) {
                        $rowspan = $dayRowCount - $blockIndex;
                    }
                    $rowspanAttr = $rowspan > 1 ? ' rowspan="'.$rowspan.'"' : '';

                    $backgroundColor = '#f0f0f0'; // Default color

                    if (! empty($block['is_reserved'])) {
                        $backgroundColor = '#e7e5e4';
                    } elseif (! empty($block['is_blocker'])) {
                        $backgroundColor = '#fee2e2';
                    } elseif (! empty($block['course_key'])) {
                        $courseKey = $block['course_key'];
                        $hslColor = $this->getCourseColor($courseKey, $colorMap);
                        $backgroundColor = $this->hslToHex($hslColor, true); // PDF needs hex with #
                    }

                    $cellContent = '<div class="entry-content">'.implode('', $block['html']).'</div>';
                    $html .= '<td'.$rowspanAttr.' style="background-color:'.$backgroundColor.';">'.$cellContent.'</td>';
                }

                $html .= '</tr>';
            }
        }

        $html .= '</tbody></table>';
        $html .= '<div style="text-align:center; margin-top:20px; font-size:12px;">
        تم إنشاء الجدول في '.date('Y-m-d H:i').' | نظام جدولة المحاضرات
    </div>';

        // خانات التوقيع: العنوان، تحته مساحة فارغة للتوقيع، ثم اسم المسؤول
        if (! empty($signatureRows)) {
            $signatureCells = '';
            foreach ($signatureRows as $signatureRow) {
                $signatureCells .= '<td dir="rtl" style="border: none; width: 33%; text-align: center; vertical-align: top; padding: 10px 20px 0 20px;">'
                    .'<div style="font-weight: bold; font-size: 14px;">'.htmlspecialchars($signatureRow['title']).'</div>'
                    // المسافة الفارغة (&nbsp;) تمنع mPDF من طيّ الحاوية الفارغة
                    .'<div style="height: 55px;">&nbsp;</div>'
                    .'<div style="font-weight: bold; font-size: 13px;">'.htmlspecialchars($signatureRow['name']).'</div>'
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
