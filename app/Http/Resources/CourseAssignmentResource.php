<?php

namespace App\Http\Resources;

use App\Models\LecturerAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseAssignmentResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        $locale = $request->header('Accept-Language', 'en');

        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'courseCode' => $this->course->code,

            'course' => $this->whenLoaded('course', function () use ($locale) {
                return [
                    'name' => $locale === 'ar' ? $this->course->name_ar : $this->course->name_en,
                    'nameEn' => $this->course->name_en,
                    'nameAr' => $this->course->name_ar,

                ];
            }),



            'lecture_groups' => $this->lecture_groups,
            'lab_groups' => $this->lab_groups,
            'practical_in_labs' => (bool) $this->practical_in_labs,

            'is_common' => (bool) $this->is_common,
            'common_study_plan_id' => $this->common_study_plan_id,
            'common_course_id' => $this->common_course_id,

            'commonStudyPlan' => $this->whenLoaded('commonStudyPlan', function () use ($locale) {
                return [
                    'id' => $this->commonStudyPlan->id,
                    'name' => $locale === 'ar' ? $this->commonStudyPlan->name_ar : $this->commonStudyPlan->name_en,
                    'nameEn' => $this->commonStudyPlan->name_en,
                    'nameAr' => $this->commonStudyPlan->name_ar,
                ];
            }),

            'commonCourse' => $this->whenLoaded('commonCourse', function () use ($locale) {
                return [
                    'id' => $this->commonCourse->id,
                    'course_id' => $this->commonCourse->course_id,
                    'courseCode' => $this->commonCourse->course->code,
                    'lecture_groups' => $this->commonCourse->lecture_groups,
                    'lab_groups' => $this->commonCourse->lab_groups,
                    'course' => $this->commonCourse->course ? [
                        'id' => $this->commonCourse->course->id,
                        'name' => $locale === 'ar' ? $this->commonCourse->course->name_ar : $this->commonCourse->course->name_en,
                        'nameEn' => $this->commonCourse->course->name_en,
                        'nameAr' => $this->commonCourse->course->name_ar,
                        'code' => $this->commonCourse->course->code,
                    ] : null,
                ];
            }),

            'lecturers' => LecturerAssignmentResource::collection(
                $this->whenLoaded('lecturerAssignments')
                    ->where('type', 'lecturer')
                    ->values()
            ),

            'teachingAssistants' => LecturerAssignmentResource::collection(
                $this->whenLoaded('lecturerAssignments')
                    ->where('type', 'teaching_assistant')
                    ->values()
            ),

            'preferredLabs' => $this->whenLoaded('preferredLabs', function () {
                return $this->preferredLabs->map(function ($lab) {
                    return [
                        'name' => $lab->name,
                        'id' => $lab->id,
                    ];
                })->values();
            }),


        ];
    }
}
