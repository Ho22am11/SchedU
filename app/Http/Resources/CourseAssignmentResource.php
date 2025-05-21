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
            'id' => $this->id ,
            'course_id'=> $this->course_id,
            'courseCode'=> $this->course->code,

            'course' => $this->whenLoaded('course', function() use ($locale) {
                return [ 'name' => $locale === 'ar' ? $this->course->name_ar : $this->course->name_en,
                'nameEn' => $this->course->name_en,
                'nameAr' => $this->course->name_ar,
            
            ];
            }),

            'lecture_groups'   => $this->lecture_groups,
            'lab_groups'       => $this->lab_groups,
            'is_common'        => (bool) $this->is_common,
            'practical_in_labs'=> (bool) $this->practical_in_labs,
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

            'preferredLabs' => $this->whenLoaded('preferredLabs', function() {
                return $this->preferredLabs->map(function($lab) {
                    return [
                        'name' => $lab->name, 
                        'id'   =>  $lab->id, 
                    ];
                })->values();
            }),
            
        
        ];
    }
}
