<?php

namespace App\Http\Controllers;

use App\Http\Resources\lecturerResource;
use App\Models\Lecturer;
use App\Services\DeletionGuard;
use App\Traits\ApiResponseTrait;
use App\Traits\ValidatesBlockers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LecturerController extends Controller
{
    use ApiResponseTrait;
    use ValidatesBlockers;

    public function __construct(private DeletionGuard $deletionGuard) {}

    public function index()
    {
        $lecturers = Lecturer::with(['department', 'timingPreference', 'academicDegree', 'blockers'])->get();

        return $this->ApiResponse(lecturerResource::collection($lecturers), 'get lecturers successfully', 200);

    }

    public function store(Request $request)
    {
        $createdStaff = [];

        DB::transaction(function () use ($request, &$createdStaff) {

            foreach ($request['staff'] as $index => $staffData) {
                $staffMember = Lecturer::create([
                    'name' => $staffData['nameEn'],
                    'name_ar' => $staffData['nameAr'] ?? null,
                    'department_id' => $staffData['department_id'],
                    'academic_id' => $staffData['academic_degree_id'],
                    'isPermanent' => $staffData['isPermanent'],
                ]);

                foreach ($staffData['timingPreference'] as $tp) {
                    $staffMember->timingPreference()->create([
                        'day' => $tp['day'],
                        'startTime' => $tp['startTime'],
                        'endTime' => $tp['endTime'],
                    ]);
                }

                $blockers = $staffData['blockers'] ?? [];
                $this->validateBlockers($blockers, "staff.{$index}.blockers");
                foreach ($blockers as $blocker) {
                    $staffMember->blockers()->create([
                        'day' => $blocker['day'],
                        'startTime' => $blocker['startTime'],
                        'endTime' => $blocker['endTime'],
                        'label' => $blocker['label'],
                    ]);
                }
                $createdStaff[] = $staffMember->load(['department', 'timingPreference', 'academicDegree', 'blockers']);
            }
        });

        return $this->ApiResponse(lecturerResource::collection($createdStaff), 'get lecturers successfully', 200);
    }

    public function show($id)
    {
        $lecturer = Lecturer::with(['department', 'timingPreference', 'academicDegree', 'blockers'])->find($id);
        if (! $lecturer) {
            return $this->ApiResponse(null, 'Lecturer not found', 404);
        }

        return $this->ApiResponse(new lecturerResource($lecturer), 'showed lecturer successfully', 200);

    }

    public function update(Request $request, $id)
    {
        $updatedLecturer = [];

        DB::transaction(function () use ($request, $id) {
            $lecturer = Lecturer::findOrFail($id);

            $lecturer->update([
                'name' => $request['nameEn'],
                'name_ar' => $request['nameAr'] ?? null,
                'department_id' => $request['department_id'],
                'academic_id' => $request['academic_degree_id'],
                'isPermanent' => $request['isPermanent'],
            ]);

            $lecturer->timingPreference()->delete();

            foreach ($request['timingPreference'] as $tp) {
                $lecturer->timingPreference()->create([
                    'day' => $tp['day'],
                    'startTime' => $tp['startTime'],
                    'endTime' => $tp['endTime'],
                ]);
            }

            $blockers = $request['blockers'] ?? [];
            $this->validateBlockers($blockers, 'blockers');

            $lecturer->blockers()->delete();

            foreach ($blockers as $blocker) {
                $lecturer->blockers()->create([
                    'day' => $blocker['day'],
                    'startTime' => $blocker['startTime'],
                    'endTime' => $blocker['endTime'],
                    'label' => $blocker['label'],
                ]);
            }
        });

        $updatedLecturer = Lecturer::with(['department', 'timingPreference', 'academicDegree', 'blockers'])->findOrFail($id);

        return $this->ApiResponse(new lecturerResource($updatedLecturer), 'lecturer updated successfully', 200);
    }

    public function destroy(Request $request, $id)
    {
        $lecturer = Lecturer::findOrFail($id);

        $blocked = $this->deletionGuard->guard(
            'lecturer',
            [$lecturer->id],
            $request->boolean('force'),
            function () use ($lecturer) {
                $lecturer->timingPreference()->delete();
                $lecturer->blockers()->delete();
                $lecturer->delete();
            }
        );

        if ($blocked !== null) {
            return $blocked;
        }

        return $this->ApiResponse(null, 'delete lecturer successfully', 200);

    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:lecturers,id'],
        ]);

        $blocked = $this->deletionGuard->guard(
            'lecturer',
            $validated['ids'],
            $request->boolean('force'),
            function () use ($validated) {
                Lecturer::whereKey($validated['ids'])->get()->each(function (Lecturer $lecturer) {
                    $lecturer->timingPreference()->delete();
                    $lecturer->blockers()->delete();
                    $lecturer->delete();
                });
            }
        );

        if ($blocked !== null) {
            return $blocked;
        }

        return $this->ApiResponse(null, 'deleted lecturers successfully', 200);
    }

    public function getStaffByType(Request $request)
    {
        $type = $request->query('type', '');

        $query = Lecturer::with(['department', 'academicDegree', 'timingPreference', 'blockers']);

        if ($type === 'lecturers') {
            $lecturerDegrees = ['professor', 'associate professor', 'assistant professor'];
            $query->whereHas('academicDegree', function ($q) use ($lecturerDegrees) {
                $q->whereIn('name', $lecturerDegrees);
            });
        } elseif ($type === 'teaching_assistant') {
            $taDegrees = ['assistant lecturer', 'teaching assistant'];
            $query->whereHas('academicDegree', function ($q) use ($taDegrees) {
                $q->whereIn('name', $taDegrees);
            });
        }

        $staff = $query->get();

        return $this->ApiResponse(lecturerResource::collection($staff), 'get lecturer successfully', 200);

    }
}
