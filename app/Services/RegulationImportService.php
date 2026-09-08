<?php

namespace App\Services;

use App\Models\Academic;
use App\Models\AcademicDegree;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\Department;
use App\Models\Lecturer;
use App\Models\LecturerAssignment;
use App\Models\StudyPlane;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegulationImportService
{
    private const SOURCE_SYSTEM = 'fcai-damietta-regulations';

    private const UNASSIGNED_INSTRUCTORS = ['دراسة ذاتية', '---', 'غير محدد', ''];

    private const UNSCHEDULED_INSTRUCTORS = ['دراسة ذاتية', 'أعضاء هيئة التدريس'];

    private const PROGRAM_DEPARTMENTS = [
        'General' => 'general',
        'AI' => 'artificial intelligence',
        'CS' => 'computer science',
        'IT' => 'information technology',
        'IS' => 'information systems',
        'DF' => 'cybersecurity',
        'MED' => 'biomedical',
        'DIP_IS' => 'information systems',
        'DIP_IT' => 'information technology',
        'MSC_CS' => 'computer science',
        'MSC_IT' => 'information technology',
        'MSC_IS' => 'information systems',
        'PHD_CS' => 'computer science',
    ];

    private const DEPARTMENT_ALIASES = [
        'المعلوماتية الطبية' => 'biomedical',
    ];

    private const ACADEMIC_DEGREE_ALIASES = [
        'دكتور خارجي' => 'مدرس',
    ];

    private const IMPORT_ENTITY_TABLES = [
        'academic' => 'academics',
        'course' => 'courses',
        'study_plan' => 'study_planes',
        'course_assignment' => 'course_assignments',
    ];

    public function preview(UploadedFile $file): array
    {
        [$document, $hash] = $this->readDocument($file);

        $preview = $this->buildPreview($document, $hash);
        $preview['recovery'] = $this->recoveryPreview($preview);

        return $preview;
    }

    public function import(
        UploadedFile $file,
        array $departmentMappings,
        array $commonMappings,
        bool $createPlaceholderTAs,
        ?int $userId
    ): array {
        [$document, $hash] = $this->readDocument($file);
        $preview = $this->buildPreview($document, $hash);

        $this->assertImportable($preview, $departmentMappings, $commonMappings, $createPlaceholderTAs);

        return DB::transaction(function () use (
            $file,
            $hash,
            $preview,
            $departmentMappings,
            $commonMappings,
            $createPlaceholderTAs,
            $userId
        ) {
            $this->purgeStaleImportAudits();

            $existing = DB::table('regulation_imports')
                ->where('source_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return [
                    'alreadyImported' => true,
                    'importId' => $existing->id,
                    'summary' => json_decode($existing->summary, true),
                ];
            }

            $existingSource = DB::table('regulation_import_mappings')
                ->whereIn('source_key', $preview['sourceKeys'])
                ->exists();

            if ($existingSource) {
                throw ValidationException::withMessages([
                    'file' => ['This regulation source has already been imported from a different file. Existing records will not be changed.'],
                ]);
            }

            $importId = DB::table('regulation_imports')->insertGetId([
                'source_system' => self::SOURCE_SYSTEM,
                'source_hash' => $hash,
                'original_filename' => $file->getClientOriginalName(),
                'academic_year' => $preview['academicYear'],
                'term' => $preview['term'],
                'status' => 'completed',
                'options' => json_encode([
                    'createPlaceholderTAs' => $createPlaceholderTAs,
                    'commonMappings' => $commonMappings,
                ]),
                'summary' => json_encode($preview['summary']),
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $departments = Department::query()->get()->keyBy('name');
            $degreeIds = AcademicDegree::query()->pluck('id', 'name_ar');
            $externalDepartment = null;
            $staff = [];
            $academicIds = [];
            $courseIds = [];
            $studyPlanIds = [];
            $assignmentIds = [];
            $placeholderNumber = 1;

            foreach ($preview['regulations'] as $regulation) {
                $academicDepartment = $departments->get(self::PROGRAM_DEPARTMENTS[$regulation['programCode']] ?? null);
                if (! $academicDepartment) {
                    throw ValidationException::withMessages([
                        'file' => ["No department is configured for program {$regulation['programCode']}"],
                    ]);
                }

                $academic = Academic::create([
                    'name' => $regulation['displayName'],
                    'name_ar' => $regulation['displayName'],
                    'department_id' => $academicDepartment->id,
                ]);
                $academicIds[$regulation['sourceKey']] = $academic->id;
                $this->recordMapping($importId, $regulation['sourceKey'], 'academic', $academic->id, $regulation);

                foreach ($regulation['courses'] as $course) {
                    $createdCourse = Course::create([
                        'code' => $course['code'],
                        'name_en' => $course['name'],
                        'name_ar' => $course['name'],
                        'practical_components' => $course['prerequisite'] ?? '',
                        'lecture_hours' => $course['lectureHours'],
                        'practical_hours' => $course['practicalHours'],
                        'credit_hours' => $course['creditHours'],
                        'academic_id' => $academic->id,
                    ]);
                    $courseIds[$course['sourceKey']] = $createdCourse->id;
                    $this->recordMapping($importId, $course['sourceKey'], 'course', $createdCourse->id, $course);
                }
            }

            foreach ($preview['studyPlans'] as $plan) {
                $studyPlan = StudyPlane::create([
                    'name_en' => $plan['nameEn'],
                    'name_ar' => $plan['nameAr'],
                    'academic_id' => $academicIds[$plan['academicSourceKey']],
                    'academicLevel' => $plan['level'],
                    'expected_students' => $plan['expectedStudents'],
                ]);
                $studyPlanIds[$plan['sourceKey']] = $studyPlan->id;
                $this->recordMapping($importId, $plan['sourceKey'], 'study_plan', $studyPlan->id, $plan);

                foreach ($plan['courses'] as $course) {
                    $isCommonChild = $this->isCommonChild($course['assignmentSourceKey'], $commonMappings);
                    $labGroups = $this->labGroups($course);
                    $assignment = CourseAssignment::create([
                        'study_plan_id' => $studyPlan->id,
                        'course_id' => $courseIds[$course['sourceKey']],
                        'lecture_groups' => $isCommonChild ? 0 : max(1, (int) $course['lectureGroups']),
                        'lab_groups' => $isCommonChild ? 0 : $labGroups,
                        'is_common' => false,
                        'practical_in_labs' => $course['sectionType'] === 'lab',
                    ]);
                    $assignmentIds[$course['assignmentSourceKey']] = $assignment->id;
                    $this->recordMapping($importId, $course['assignmentSourceKey'], 'course_assignment', $assignment->id, $course);

                    if ($isCommonChild) {
                        continue;
                    }

                    foreach ($this->assignableInstructors($course) as $instructor) {
                        $lecturerId = $this->resolveStaff(
                            $instructor['name'],
                            $instructor['academicDegree'],
                            $instructor['specialization'],
                            'lecturer',
                            $departmentMappings,
                            $degreeIds,
                            $staff
                        );

                        LecturerAssignment::create([
                            'course_assignment_id' => $assignment->id,
                            'lecturer_id' => $lecturerId,
                            'num_groups' => $instructor['lectureGroups'],
                            'type' => 'lecturer',
                        ]);
                    }

                    $assignedTaGroups = 0;
                    foreach ($course['teachingAssistants'] as $assistant) {
                        if (($assistant['isPlaceholder'] ?? false) || empty($assistant['name'])) {
                            continue;
                        }

                        $groups = max(0, (int) ($assistant['sectionGroups'] ?? 0));
                        if ($groups === 0) {
                            continue;
                        }

                        $taDepartment = trim((string) ($assistant['department'] ?? $assistant['specialization'] ?? ''));
                        if ($taDepartment === '' || $taDepartment === 'غير محدد') {
                            $taDepartment = '__teaching_assistants__';
                        }

                        $taId = $this->resolveStaff(
                            $assistant['name'],
                            'معيد',
                            $taDepartment,
                            'teaching_assistant',
                            $departmentMappings,
                            $degreeIds,
                            $staff
                        );

                        LecturerAssignment::create([
                            'course_assignment_id' => $assignment->id,
                            'lecturer_id' => $taId,
                            'num_groups' => $groups,
                            'type' => 'teaching_assistant',
                        ]);
                        $assignedTaGroups += $groups;
                    }

                    if ($createPlaceholderTAs && $assignedTaGroups < $labGroups) {
                        $externalDepartment = $this->externalDepartment($externalDepartment);
                        while ($assignedTaGroups < $labGroups) {
                            $placeholderId = $this->createPlaceholderTa(
                                $placeholderNumber++,
                                $degreeIds,
                                $externalDepartment,
                                $staff
                            );
                            LecturerAssignment::create([
                                'course_assignment_id' => $assignment->id,
                                'lecturer_id' => $placeholderId,
                                'num_groups' => 1,
                                'type' => 'teaching_assistant',
                            ]);
                            $assignedTaGroups++;
                        }
                    }
                }
            }

            foreach ($commonMappings as $childSourceKey => $parentSourceKey) {
                if ($parentSourceKey === 'independent' || $parentSourceKey === '') {
                    continue;
                }

                $child = CourseAssignment::findOrFail($assignmentIds[$childSourceKey]);
                $child->lecturerAssignments()->delete();
                $child->update([
                    'lecture_groups' => 0,
                    'lab_groups' => 0,
                    'is_common' => true,
                    'common_study_plan_id' => $studyPlanIds[$this->planKeyFromAssignmentKey($parentSourceKey)],
                    'common_course_id' => $assignmentIds[$parentSourceKey],
                ]);
            }

            return [
                'alreadyImported' => false,
                'importId' => $importId,
                'summary' => $preview['summary'],
            ];
        });
    }

    public function repair(
        UploadedFile $file,
        array $departmentMappings,
        array $commonMappings,
        bool $createPlaceholderTAs
    ): array {
        [$document, $hash] = $this->readDocument($file);
        $preview = $this->buildPreview($document, $hash);
        $state = $this->recoveryState($preview);

        $this->assertImportable($preview, $departmentMappings, $commonMappings, $createPlaceholderTAs);

        $missingWithoutCourses = array_filter($state['missing'], static fn (array $item): bool => $item['courseId'] === null);
        if ($missingWithoutCourses !== []) {
            throw ValidationException::withMessages([
                'file' => ['One or more missing assignments cannot be restored because their course records no longer exist.'],
            ]);
        }

        $missingSourceKeys = array_map(
            static fn (array $item): string => $item['course']['assignmentSourceKey'],
            $state['missing']
        );
        foreach ($commonMappings as $childSourceKey => $parentSourceKey) {
            if (! in_array($childSourceKey, $missingSourceKeys, true) || in_array($parentSourceKey, ['independent', ''], true)) {
                continue;
            }
            if (! isset($state['assignmentIds'][$parentSourceKey]) && ! in_array($parentSourceKey, $missingSourceKeys, true)) {
                throw ValidationException::withMessages([
                    'commonMappings' => ['Choose an existing parent course for every restored common course.'],
                ]);
            }
        }

        if ($state['missing'] === []) {
            return ['restoredAssignments' => 0, 'skippedStudyPlans' => $state['skippedStudyPlans']];
        }

        return DB::transaction(function () use ($state, $departmentMappings, $commonMappings, $createPlaceholderTAs, $missingSourceKeys) {
            $degreeIds = AcademicDegree::query()->pluck('id', 'name_ar');
            $staff = [];
            $externalDepartment = null;
            $placeholderNumber = 1;
            $assignmentIds = $state['assignmentIds'];

            foreach ($state['missing'] as $item) {
                $course = $item['course'];
                $assignment = CourseAssignment::create([
                    'study_plan_id' => $item['planId'],
                    'course_id' => $item['courseId'],
                    'lecture_groups' => max(1, (int) $course['lectureGroups']),
                    'lab_groups' => $this->labGroups($course),
                    'is_common' => false,
                    'practical_in_labs' => $course['sectionType'] === 'lab',
                ]);
                $assignmentIds[$course['assignmentSourceKey']] = $assignment->id;
                $this->restoreMapping($course['assignmentSourceKey'], 'course_assignment', $assignment->id, $course);

                foreach ($this->assignableInstructors($course) as $instructor) {
                    $lecturerId = $this->resolveStaff(
                        $instructor['name'],
                        $instructor['academicDegree'],
                        $instructor['specialization'],
                        'lecturer',
                        $departmentMappings,
                        $degreeIds,
                        $staff
                    );
                    LecturerAssignment::create([
                        'course_assignment_id' => $assignment->id,
                        'lecturer_id' => $lecturerId,
                        'num_groups' => $instructor['lectureGroups'],
                        'type' => 'lecturer',
                    ]);
                }

                $assignedTaGroups = 0;
                foreach ($course['teachingAssistants'] as $assistant) {
                    if (($assistant['isPlaceholder'] ?? false) || empty($assistant['name'])) {
                        continue;
                    }
                    $groups = max(0, (int) ($assistant['sectionGroups'] ?? 0));
                    if ($groups === 0) {
                        continue;
                    }
                    $department = trim((string) ($assistant['department'] ?? ''));
                    $department = $department === '' || $department === 'غير محدد' ? '__teaching_assistants__' : $department;
                    $taId = $this->resolveStaff(
                        $assistant['name'],
                        'معيد',
                        $department,
                        'teaching_assistant',
                        $departmentMappings,
                        $degreeIds,
                        $staff
                    );
                    LecturerAssignment::create([
                        'course_assignment_id' => $assignment->id,
                        'lecturer_id' => $taId,
                        'num_groups' => $groups,
                        'type' => 'teaching_assistant',
                    ]);
                    $assignedTaGroups += $groups;
                }

                $labGroups = $this->labGroups($course);
                if ($createPlaceholderTAs && $assignedTaGroups < $labGroups) {
                    $externalDepartment = $this->externalDepartment($externalDepartment);
                    while ($assignedTaGroups < $labGroups) {
                        $placeholderId = $this->createPlaceholderTa(
                            $placeholderNumber++,
                            $degreeIds,
                            $externalDepartment,
                            $staff
                        );
                        LecturerAssignment::create([
                            'course_assignment_id' => $assignment->id,
                            'lecturer_id' => $placeholderId,
                            'num_groups' => 1,
                            'type' => 'teaching_assistant',
                        ]);
                        $assignedTaGroups++;
                    }
                }
            }

            foreach ($commonMappings as $childSourceKey => $parentSourceKey) {
                if (! in_array($childSourceKey, $missingSourceKeys, true) || in_array($parentSourceKey, ['independent', ''], true)) {
                    continue;
                }

                $child = CourseAssignment::findOrFail($assignmentIds[$childSourceKey]);
                $child->lecturerAssignments()->delete();
                $child->update([
                    'lecture_groups' => 0,
                    'lab_groups' => 0,
                    'is_common' => true,
                    'common_study_plan_id' => $this->studyPlanIdForAssignment($parentSourceKey, $state),
                    'common_course_id' => $assignmentIds[$parentSourceKey],
                ]);
            }

            return [
                'restoredAssignments' => count($state['missing']),
                'skippedStudyPlans' => $state['skippedStudyPlans'],
            ];
        });
    }

    private function readDocument(UploadedFile $file): array
    {
        $contents = $file->get();
        if ($contents === false || $contents === '') {
            throw ValidationException::withMessages(['file' => ['The uploaded file is empty.']]);
        }

        try {
            $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['file' => ['The uploaded file is not valid JSON.']]);
        }

        if (! is_array($document) || ! isset($document['regulations']) || ! is_array($document['regulations'])) {
            throw ValidationException::withMessages(['file' => ['The JSON file does not contain a regulations array.']]);
        }

        return [$document, hash('sha256', $contents)];
    }

    private function recoveryPreview(array $preview): array
    {
        $state = $this->recoveryState($preview);

        return [
            'missingAssignments' => array_map(static fn (array $item): array => [
                'sourceKey' => $item['course']['assignmentSourceKey'],
                'studyPlanName' => $item['plan']['nameAr'],
                'courseCode' => $item['course']['code'],
                'courseName' => $item['course']['name'],
            ], $state['missing']),
            'skippedStudyPlans' => $state['skippedStudyPlans'],
        ];
    }

    private function recoveryState(array $preview): array
    {
        $planKeys = array_column($preview['studyPlans'], 'sourceKey');
        $planIds = DB::table('regulation_import_mappings')
            ->where('entity_type', 'study_plan')
            ->whereIn('source_key', $planKeys)
            ->pluck('target_id', 'source_key');
        $livePlanIds = DB::table('study_planes')
            ->whereIn('id', $planIds->values())
            ->pluck('id')
            ->flip();

        $courseKeys = [];
        foreach ($preview['studyPlans'] as $plan) {
            foreach ($plan['courses'] as $course) {
                $courseKeys[] = $course['sourceKey'];
            }
        }
        $courseIds = DB::table('regulation_import_mappings')
            ->where('entity_type', 'course')
            ->whereIn('source_key', array_unique($courseKeys))
            ->pluck('target_id', 'source_key');
        $liveCourseIds = DB::table('courses')
            ->whereIn('id', $courseIds->values())
            ->pluck('id')
            ->flip();

        $existingAssignments = DB::table('course_assignments')
            ->whereIn('study_plan_id', $livePlanIds->keys())
            ->get(['id', 'study_plan_id', 'course_id'])
            ->groupBy(static fn ($assignment) => "{$assignment->study_plan_id}:{$assignment->course_id}")
            ->map(static fn ($assignments) => $assignments->pluck('id')->all())
            ->all();

        $assignmentIds = [];
        $missing = [];
        $skippedStudyPlans = 0;
        foreach ($preview['studyPlans'] as $plan) {
            $planId = $planIds[$plan['sourceKey']] ?? null;
            if (! $planId || ! isset($livePlanIds[$planId])) {
                $skippedStudyPlans++;

                continue;
            }

            foreach ($plan['courses'] as $course) {
                $courseId = $courseIds[$course['sourceKey']] ?? null;
                if (! $courseId || ! isset($liveCourseIds[$courseId])) {
                    $missing[] = [
                        'plan' => $plan,
                        'planId' => $planId,
                        'course' => $course,
                        'courseId' => null,
                    ];

                    continue;
                }

                $key = "{$planId}:{$courseId}";
                $assignmentIdsForCourse = $existingAssignments[$key] ?? [];
                $assignmentId = array_shift($assignmentIdsForCourse);
                $existingAssignments[$key] = $assignmentIdsForCourse;
                if ($assignmentId) {
                    $assignmentIds[$course['assignmentSourceKey']] = $assignmentId;

                    continue;
                }

                $missing[] = [
                    'plan' => $plan,
                    'planId' => $planId,
                    'course' => $course,
                    'courseId' => $courseId,
                ];
            }
        }

        return compact('assignmentIds', 'missing', 'planIds', 'skippedStudyPlans');
    }

    private function buildPreview(array $document, string $hash): array
    {
        $term = (int) Arr::get($document, 'exportFilter.termFilter') === 1 ? 1 : (int) preg_replace('/\D+/', '', (string) Arr::get($document, 'exportFilter.termFilter', '1'));
        $term = $term > 0 ? $term : 1;
        $regulations = [];
        $studyPlans = [];
        $specializations = [];
        $issues = [];
        $sourceKeys = [];
        $courseCount = 0;
        $offeredCourseCount = 0;

        foreach ($document['regulations'] as $regulationIndex => $rawRegulation) {
            if ($this->isExternalRecord($rawRegulation)) {
                continue;
            }

            $this->assertFields($rawRegulation, ['id', 'name', 'programCode', 'sections'], "regulations.{$regulationIndex}");
            if (! is_array($rawRegulation['sections'])) {
                throw ValidationException::withMessages(['file' => ["regulations.{$regulationIndex}.sections must be an array."]]);
            }

            $regulation = [
                'id' => (string) $rawRegulation['id'],
                'name' => (string) $rawRegulation['name'],
                'programCode' => (string) $rawRegulation['programCode'],
                'displayName' => $this->withProgramIdentifier(
                    (string) $rawRegulation['name'],
                    (string) $rawRegulation['programCode']
                ),
                'sourceKey' => $this->academicKey($rawRegulation),
                'courses' => [],
            ];
            $sourceKeys[] = $regulation['sourceKey'];
            $courseKeys = [];

            foreach ($rawRegulation['sections'] as $sectionIndex => $rawSection) {
                if ($this->isExternalRecord($rawSection)) {
                    continue;
                }

                $this->assertFields($rawSection, ['level', 'courses'], "regulations.{$regulationIndex}.sections.{$sectionIndex}");
                if (! is_array($rawSection['courses'])) {
                    throw ValidationException::withMessages(['file' => ["Section {$sectionIndex} in {$regulation['name']} has invalid courses."]]);
                }

                $offeredCourses = [];
                foreach ($rawSection['courses'] as $courseIndex => $rawCourse) {
                    if ($this->isExternalRecord($rawCourse)) {
                        continue;
                    }

                    $this->assertFields($rawCourse, ['id', 'code', 'name', 'lectureHours', 'practicalHours', 'creditHours'], "course {$courseIndex}");
                    $course = $this->normaliseCourse($rawCourse, $regulation, (int) $rawSection['level']);
                    if (! isset($courseKeys[$course['sourceKey']])) {
                        $regulation['courses'][] = $course;
                        $courseKeys[$course['sourceKey']] = true;
                        $sourceKeys[] = $course['sourceKey'];
                        $courseCount++;
                    }

                    if ($this->isExcludedFromSchedule($rawCourse)) {
                        continue;
                    }

                    if (empty($rawCourse['isOffered'])) {
                        continue;
                    }

                    $course['assignmentSourceKey'] = $this->assignmentKey($rawRegulation, $rawSection, $rawCourse);
                    $offeredCourses[] = $course;
                    $offeredCourseCount++;
                    $sourceKeys[] = $course['assignmentSourceKey'];
                    $this->collectStaffRequirements($course, $specializations, $issues);
                    $this->collectSchedulingIssues($course, $issues);
                }

                if ($offeredCourses === []) {
                    continue;
                }

                $expectedStudentCounts = array_map(
                    static fn (array $course): int => max(1, (int) $course['studentCount']),
                    $offeredCourses
                );
                $plan = [
                    'sourceKey' => $this->studyPlanKey($rawRegulation, $rawSection),
                    'academicSourceKey' => $regulation['sourceKey'],
                    'level' => (int) $rawSection['level'],
                    'expectedStudents' => max($expectedStudentCounts),
                    'nameEn' => $this->withProgramIdentifier(
                        (string) ($rawSection['title'] ?? "Level {$rawSection['level']}"),
                        $regulation['programCode']
                    ),
                    'nameAr' => $this->withProgramIdentifier(
                        "{$regulation['name']} - المستوى {$rawSection['level']} - الفصل الدراسي الأول",
                        $regulation['programCode']
                    ),
                    'courses' => $offeredCourses,
                ];
                $studyPlans[] = $plan;
                $sourceKeys[] = $plan['sourceKey'];
            }

            $regulations[] = $regulation;
        }

        return [
            'sourceHash' => $hash,
            'academicYear' => (string) ($document['academicYear'] ?? ''),
            'term' => $term,
            'summary' => [
                'academicLists' => count($regulations),
                'courses' => $courseCount,
                'studyPlans' => count($studyPlans),
                'offeredCourseAssignments' => $offeredCourseCount,
                'staffSpecializations' => count($specializations),
            ],
            'specializations' => array_values($specializations),
            'commonCourseCandidates' => $this->commonCourseCandidates($studyPlans),
            'issues' => $issues,
            'sourceKeys' => array_values(array_unique($sourceKeys)),
            'regulations' => $regulations,
            'studyPlans' => $studyPlans,
        ];
    }

    private function normaliseCourse(array $course, array $regulation, int $level): array
    {
        $instructors = is_array($course['instructors'] ?? null)
            ? array_map(static fn (array $instructor): array => [
                'name' => trim((string) ($instructor['name'] ?? '')),
                'lectureGroups' => max(0, (int) ($instructor['lectureGroups'] ?? 0)),
                'academicDegree' => trim((string) ($instructor['academicDegree'] ?? '')),
                'specialization' => trim((string) ($instructor['specialization'] ?? '')),
            ], $course['instructors'])
            : [[
                'name' => trim((string) ($course['instructor'] ?? '')),
                'lectureGroups' => max(1, (int) ($course['lectureGroups'] ?? 1)),
                'academicDegree' => trim((string) ($course['academicDegree'] ?? '')),
                'specialization' => trim((string) ($course['specialization'] ?? '')),
            ]];

        return [
            'id' => (string) $course['id'],
            'sourceKey' => $this->courseKey($regulation, $course),
            'code' => trim((string) $course['code']),
            'name' => trim((string) $course['name']),
            'prerequisite' => trim((string) ($course['prerequisite'] ?? '')),
            'lectureHours' => max(0, (int) $course['lectureHours']),
            'practicalHours' => max(0, (int) $course['practicalHours']),
            'creditHours' => max(0, (int) $course['creditHours']),
            'instructors' => $instructors,
            'studentCount' => max(1, (int) ($course['studentCount'] ?? 1)),
            'lectureGroups' => max(1, (int) ($course['lectureGroups'] ?? 1)),
            'sectionType' => (string) ($course['sectionType'] ?? 'none'),
            'totalSectionGroupsNeeded' => max(0, (int) ($course['totalSectionGroupsNeeded'] ?? 0)),
            'teachingAssistants' => is_array($course['teachingAssistants'] ?? null)
                ? array_map(static fn (array $assistant): array => [
                    'name' => trim((string) ($assistant['name'] ?? '')),
                    'department' => trim((string) ($assistant['department'] ?? $assistant['specialization'] ?? '')),
                    'sectionGroups' => max(0, (int) ($assistant['sectionGroups'] ?? 0)),
                    'isPlaceholder' => (bool) ($assistant['isPlaceholder'] ?? false),
                ], $course['teachingAssistants'])
                : [],
            'level' => $level,
        ];
    }

    private function collectStaffRequirements(array $course, array &$specializations, array &$issues): void
    {
        foreach ($this->assignableInstructors($course) as $instructor) {
            if ($instructor['academicDegree'] === '') {
                $issues[] = $this->issue('error', 'missing_degree', $course, 'The instructor has no academic degree.');
            }
            if ($instructor['specialization'] === '') {
                $issues[] = $this->issue('error', 'missing_specialization', $course, 'The instructor has no department specialization.');
            } else {
                $this->addSpecialization($specializations, $instructor['specialization']);
            }
        }

        foreach ($course['teachingAssistants'] as $assistant) {
            if (($assistant['isPlaceholder'] ?? false) || empty($assistant['name'])) {
                continue;
            }

            $department = $assistant['department'] ?? '';
            $this->addSpecialization(
                $specializations,
                $department === '' || $department === 'غير محدد' ? '__teaching_assistants__' : $department
            );
        }
    }

    private function collectSchedulingIssues(array $course, array &$issues): void
    {
        $instructors = $this->assignableInstructors($course);
        if ($instructors === []) {
            $issues[] = $this->issue('error', 'missing_instructor', $course, 'An offered course has no assignable instructor.');
        } else {
            $assignedLectureGroups = array_sum(array_column($instructors, 'lectureGroups'));
            if ($assignedLectureGroups !== $course['lectureGroups']) {
                $issues[] = $this->issue(
                    'error',
                    'instructor_groups_mismatch',
                    $course,
                    "Lecturer groups ({$assignedLectureGroups}) must equal the course lecture groups ({$course['lectureGroups']})."
                );
            }
        }

        $labGroups = $this->labGroups($course);
        if ($labGroups === 0) {
            return;
        }

        $assignedGroups = 0;
        foreach ($course['teachingAssistants'] as $assistant) {
            if (($assistant['isPlaceholder'] ?? false) || empty($assistant['name'])) {
                continue;
            }
            $assignedGroups += max(0, (int) ($assistant['sectionGroups'] ?? 0));
        }

        if ($assignedGroups < $labGroups) {
            $missingGroups = $labGroups - $assignedGroups;
            $issues[] = $this->issue('warning', 'missing_ta_groups', $course, "{$missingGroups} TA group(s) need coverage.");
        }
        if ($assignedGroups > $labGroups) {
            $issues[] = $this->issue('error', 'excess_ta_groups', $course, 'Assigned TA groups exceed the required number of groups.');
        }
    }

    private function addSpecialization(array &$specializations, string $key): void
    {
        if (isset($specializations[$key])) {
            return;
        }

        $suggested = null;
        if ($key !== '__teaching_assistants__') {
            $departmentName = self::DEPARTMENT_ALIASES[$key] ?? null;
            $suggested = $departmentName
                ? Department::query()->where('name', $departmentName)->value('id')
                : Department::query()->where('name_ar', $key)->value('id');
        }
        $specializations[$key] = [
            'key' => $key,
            'label' => $key === '__teaching_assistants__' ? 'Teaching assistants without a department' : $key,
            'suggestedDepartmentId' => $suggested,
        ];
    }

    private function commonCourseCandidates(array $studyPlans): array
    {
        $groups = [];
        foreach ($studyPlans as $plan) {
            foreach ($plan['courses'] as $course) {
                $key = $this->normaliseText($course['code']).'|'.$this->normaliseText($course['name']);
                $groups[$key][] = [
                    'sourceKey' => $course['assignmentSourceKey'],
                    'studyPlanSourceKey' => $plan['sourceKey'],
                    'name' => $course['name'],
                    'code' => $course['code'],
                    'courseId' => $course['id'],
                    'studyPlanName' => $plan['nameEn'],
                ];
            }
        }

        return array_values(array_map(
            static fn (array $courses): array => ['courses' => $courses],
            array_filter($groups, static fn (array $courses): bool => count($courses) > 1)
        ));
    }

    private function assertImportable(
        array $preview,
        array $departmentMappings,
        array $commonMappings,
        bool $createPlaceholderTAs
    ): void {
        $errors = [];
        foreach ($preview['issues'] as $issue) {
            if ($issue['severity'] === 'error') {
                $errors['file'][] = $issue['message'];
            }
            if ($issue['code'] === 'missing_ta_groups' && ! $createPlaceholderTAs) {
                $errors['createPlaceholderTAs'][] = 'Enable temporary placeholder TAs or resolve every TA shortage before importing.';
            }
        }

        foreach ($preview['specializations'] as $specialization) {
            $value = $departmentMappings[$specialization['key']] ?? null;
            if ($value !== 'external' && (! is_numeric($value) || ! Department::query()->whereKey((int) $value)->exists())) {
                $errors['departmentMappings'][] = "Choose a department for {$specialization['label']}.";
            }
        }

        $candidateKeys = [];
        foreach ($preview['commonCourseCandidates'] as $candidate) {
            foreach ($candidate['courses'] as $course) {
                $candidateKeys[$course['sourceKey']] = true;
            }
        }
        foreach ($commonMappings as $child => $parent) {
            if ($parent === 'independent' || $parent === '') {
                continue;
            }
            if (! isset($candidateKeys[$child]) || ! isset($candidateKeys[$parent]) || $child === $parent) {
                $errors['commonMappings'][] = 'Each common-course link must connect two distinct suggested courses.';
            }
            if (isset($commonMappings[$parent]) && ! in_array($commonMappings[$parent], ['independent', ''], true)) {
                $errors['commonMappings'][] = 'A common-course parent cannot also be selected as a child.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function resolveStaff(
        string $name,
        string $degreeArabic,
        string $specialization,
        string $type,
        array $departmentMappings,
        $degreeIds,
        array &$staff
    ): int {
        $degreeId = $degreeIds->get(self::ACADEMIC_DEGREE_ALIASES[$degreeArabic] ?? $degreeArabic);
        if (! $degreeId) {
            throw ValidationException::withMessages(['file' => ["Academic degree {$degreeArabic} is not available in the database."]]);
        }

        $departmentId = $this->mappedDepartmentId($specialization, $departmentMappings);
        $staffKey = $type.'|'.$this->normaliseText($name).'|'.$degreeId.'|'.$departmentId;
        if (isset($staff[$staffKey])) {
            return $staff[$staffKey];
        }

        $matches = Lecturer::query()
            ->where('name_ar', $name)
            ->where('academic_id', $degreeId)
            ->where('department_id', $departmentId)
            ->limit(2)
            ->get();
        if ($matches->count() === 1) {
            return $staff[$staffKey] = $matches->first()->id;
        }
        if ($matches->count() > 1) {
            throw ValidationException::withMessages(['file' => ["More than one existing staff record matches {$name}. Resolve the duplicate before importing."]]);
        }

        $member = Lecturer::create([
            'name' => $name,
            'name_ar' => $name,
            'department_id' => $departmentId,
            'academic_id' => $degreeId,
            'isPermanent' => true,
        ]);

        return $staff[$staffKey] = $member->id;
    }

    private function createPlaceholderTa(int $number, $degreeIds, ?Department $externalDepartment, array &$staff): int
    {
        $degreeId = $degreeIds->get('معيد');
        if (! $degreeId) {
            throw ValidationException::withMessages(['file' => ['The teaching assistant degree is not available in the database.']]);
        }

        $key = 'placeholder|'.$number;
        if (isset($staff[$key])) {
            return $staff[$key];
        }

        $department = $this->externalDepartment($externalDepartment);
        $member = Lecturer::create([
            'name' => "معيد {$number}",
            'name_ar' => "معيد {$number}",
            'department_id' => $department->id,
            'academic_id' => $degreeId,
            'isPermanent' => false,
        ]);

        return $staff[$key] = $member->id;
    }

    private function mappedDepartmentId(string $specialization, array $departmentMappings): int
    {
        $mapping = $departmentMappings[$specialization] ?? null;
        if ($mapping === 'external') {
            return $this->externalDepartment(null)->id;
        }

        return (int) $mapping;
    }

    private function externalDepartment(?Department $externalDepartment): Department
    {
        if ($externalDepartment) {
            return $externalDepartment;
        }

        return Department::query()->firstOrCreate(
            ['name' => 'external'],
            ['name_ar' => 'خارجي']
        );
    }

    private function recordMapping(int $importId, string $sourceKey, string $entityType, int $targetId, array $source): void
    {
        DB::table('regulation_import_mappings')->insert([
            'regulation_import_id' => $importId,
            'source_key' => $sourceKey,
            'entity_type' => $entityType,
            'target_id' => $targetId,
            'source_checksum' => hash('sha256', json_encode($source)),
            'created_at' => now(),
        ]);
    }

    private function restoreMapping(string $sourceKey, string $entityType, int $targetId, array $source): void
    {
        $updated = DB::table('regulation_import_mappings')
            ->where('source_key', $sourceKey)
            ->where('entity_type', $entityType)
            ->update([
                'target_id' => $targetId,
                'source_checksum' => hash('sha256', json_encode($source)),
                'created_at' => now(),
            ]);

        if ($updated === 0) {
            throw ValidationException::withMessages([
                'file' => ['A missing assignment has no audit mapping and cannot be restored safely.'],
            ]);
        }
    }

    private function studyPlanIdForAssignment(string $assignmentSourceKey, array $state): int
    {
        $planSourceKey = $this->planKeyFromAssignmentKey($assignmentSourceKey);
        $planId = $state['planIds'][$planSourceKey] ?? null;
        if (! $planId) {
            throw ValidationException::withMessages([
                'commonMappings' => ['A common-course parent must belong to an existing study plan.'],
            ]);
        }

        return (int) $planId;
    }

    private function purgeStaleImportAudits(): void
    {
        $importIds = DB::table('regulation_imports')
            ->lockForUpdate()
            ->pluck('id');

        foreach ($importIds as $importId) {
            $mappings = DB::table('regulation_import_mappings')
                ->where('regulation_import_id', $importId)
                ->get(['entity_type', 'target_id']);

            if ($this->importHasLiveTargets($mappings)) {
                continue;
            }

            DB::table('regulation_imports')->where('id', $importId)->delete();
        }
    }

    private function importHasLiveTargets($mappings): bool
    {
        foreach ($mappings->groupBy('entity_type') as $entityType => $entityMappings) {
            $table = self::IMPORT_ENTITY_TABLES[$entityType] ?? null;
            if ($table === null) {
                return true;
            }

            $targetIds = $entityMappings->pluck('target_id')->unique()->values()->all();
            if ($targetIds !== [] && DB::table($table)->whereIn('id', $targetIds)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function labGroups(array $course): int
    {
        return $course['sectionType'] === 'none' ? 0 : $course['totalSectionGroupsNeeded'];
    }

    private function isExternalRecord(array $record): bool
    {
        foreach (['category', 'regulation'] as $field) {
            if (strtolower(trim((string) ($record[$field] ?? ''))) === 'external') {
                return true;
            }
        }

        return false;
    }

    private function isExcludedFromSchedule(array $course): bool
    {
        if (str_contains(strtolower(trim((string) ($course['name'] ?? ''))), 'project')) {
            return true;
        }

        if (in_array(trim((string) ($course['instructor'] ?? '')), self::UNSCHEDULED_INSTRUCTORS, true)) {
            return true;
        }

        if (! is_array($course['instructors'] ?? null) || $course['instructors'] === []) {
            return false;
        }

        return collect($course['instructors'])->every(
            static fn (array $instructor): bool => in_array(
                trim((string) ($instructor['name'] ?? '')),
                self::UNSCHEDULED_INSTRUCTORS,
                true
            )
        );
    }

    private function assignableInstructors(array $course): array
    {
        return array_values(array_filter(
            $course['instructors'],
            static fn (array $instructor): bool => ! in_array($instructor['name'], self::UNASSIGNED_INSTRUCTORS, true)
                && $instructor['lectureGroups'] > 0
        ));
    }

    private function isCommonChild(string $sourceKey, array $commonMappings): bool
    {
        return isset($commonMappings[$sourceKey])
            && ! in_array($commonMappings[$sourceKey], ['independent', ''], true);
    }

    private function issue(string $severity, string $code, array $course, string $message): array
    {
        return [
            'severity' => $severity,
            'code' => $code,
            'course' => "{$course['code']} — {$course['name']}",
            'message' => $message,
        ];
    }

    private function assertFields(array $data, array $fields, string $path): void
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $data)) {
                throw ValidationException::withMessages(['file' => ["Missing {$path}.{$field} in the source JSON."]]);
            }
        }
    }

    private function academicKey(array $regulation): string
    {
        return "regulation:{$regulation['id']}:academic";
    }

    private function courseKey(array $regulation, array $course): string
    {
        return "regulation:{$regulation['id']}:course:{$course['id']}";
    }

    private function studyPlanKey(array $regulation, array $section): string
    {
        return "regulation:{$regulation['id']}:level:{$section['level']}:study-plan";
    }

    private function assignmentKey(array $regulation, array $section, array $course): string
    {
        return "regulation:{$regulation['id']}:level:{$section['level']}:course:{$course['id']}:assignment";
    }

    private function planKeyFromAssignmentKey(string $assignmentKey): string
    {
        return preg_replace('/:course:[^:]+:assignment$/', ':study-plan', $assignmentKey);
    }

    private function normaliseText(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    }

    private function withProgramIdentifier(string $name, string $programCode): string
    {
        if (str_contains($this->normaliseText($name), $this->normaliseText($programCode))) {
            return $name;
        }

        return "[{$programCode}] {$name}";
    }
}
