<?php

namespace App\Http\Controllers;

use App\Models\Hall;
use App\Services\DeletionGuard;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HallController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private DeletionGuard $deletionGuard) {}

    public function index()
    {
        $halls = Hall::all();
        $halls->load('availability');

        return $this->ApiResponse($halls, 'get halls successfully', 200);

    }

    public function store(Request $request)
    {

        $hallsCreated = [];

        DB::transaction(function () use ($request, &$hallsCreated) {
            foreach ($request['halls'] as $hallData) {
                $hall = Hall::create([
                    'name' => $hallData['name'],
                    'capacity' => $hallData['capacity'],
                ]);

                foreach ($hallData['availability'] as $timePref) {
                    $hall->availability()->create([
                        'day' => $timePref['day'],
                        'startTime' => $timePref['startTime'],
                        'endTime' => $timePref['endTime'],
                    ]);
                }

                $hallsCreated[] = $hall->load('availability');
            }
        });

        return $this->ApiResponse($hallsCreated, 'created halls successfully', 201);

    }

    public function show($id)
    {
        $hall = Hall::with('availability')->find($id);

        return $this->ApiResponse($hall, 'get hall successfully', 200);

    }

    public function update(Request $request, $id)
    {

        DB::transaction(function () use ($request, $id) {
            $hall = Hall::findOrFail($id);

            $hall->update([
                'name' => $request['name'],
                'capacity' => $request['capacity'],
            ]);

            $hall->availability()->delete();

            foreach ($request['availability'] as $timePref) {
                $hall->availability()->create([
                    'day' => $timePref['day'],
                    'startTime' => $timePref['startTime'],
                    'endTime' => $timePref['endTime'],
                ]);
            }
        });

        $hall = Hall::with('availability')->find($id);

        return $this->ApiResponse($hall, 'updated hall  successfully', 200);
    }

    public function destroy(Request $request, $id)
    {
        $hall = Hall::findOrFail($id);

        $blocked = $this->deletionGuard->guard(
            'hall',
            [$hall->id],
            $request->boolean('force'),
            function () use ($hall) {
                $hall->availability()->delete();
                $hall->delete();
            }
        );

        if ($blocked !== null) {
            return $blocked;
        }

        return $this->ApiResponse(null, 'deleted hall  successfully', 200);

    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:halls,id'],
        ]);

        $blocked = $this->deletionGuard->guard(
            'hall',
            $validated['ids'],
            $request->boolean('force'),
            function () use ($validated) {
                Hall::whereKey($validated['ids'])->get()->each(function (Hall $hall) {
                    $hall->availability()->delete();
                    $hall->delete();
                });
            }
        );

        if ($blocked !== null) {
            return $blocked;
        }

        return $this->ApiResponse(null, 'deleted halls successfully', 200);
    }
}
