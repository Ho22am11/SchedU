<?php

namespace App\Http\Controllers;

use App\Services\RegulationImportService;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class RegulationImportController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly RegulationImportService $importService) {}

    public function preview(Request $request)
    {
        try {
            return $this->ApiResponse(
                $this->importService->preview($this->uploadedFile($request)),
                'Import preview created successfully',
                200
            );
        } catch (ValidationException $exception) {
            return $this->ApiResponse($exception->errors(), 'Import preview validation failed', 422);
        } catch (Throwable $exception) {
            report($exception);

            return $this->ApiResponse(null, 'Unable to read the import file', 422);
        }
    }

    public function store(Request $request)
    {
        try {
            return $this->ApiResponse(
                $this->importService->import(
                    $this->uploadedFile($request),
                    $this->jsonInput($request, 'departmentMappings'),
                    $this->jsonInput($request, 'commonMappings'),
                    $request->boolean('createPlaceholderTAs'),
                    $request->user()?->id
                ),
                'Regulation data imported successfully',
                201
            );
        } catch (ValidationException $exception) {
            return $this->ApiResponse($exception->errors(), 'Import validation failed', 422);
        } catch (Throwable $exception) {
            report($exception);

            return $this->ApiResponse(null, 'The import could not be completed', 500);
        }
    }

    public function repair(Request $request)
    {
        try {
            return $this->ApiResponse(
                $this->importService->repair(
                    $this->uploadedFile($request),
                    $this->jsonInput($request, 'departmentMappings'),
                    $this->jsonInput($request, 'commonMappings'),
                    $request->boolean('createPlaceholderTAs')
                ),
                'Missing study-plan assignments restored successfully',
                200
            );
        } catch (ValidationException $exception) {
            return $this->ApiResponse($exception->errors(), 'Repair validation failed', 422);
        } catch (Throwable $exception) {
            report($exception);

            return $this->ApiResponse(null, 'The repair could not be completed', 500);
        }
    }

    private function uploadedFile(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $file = $request->file('file');
        if (! $file || strtolower($file->getClientOriginalExtension()) !== 'json') {
            throw ValidationException::withMessages([
                'file' => ['Upload a JSON file.'],
            ]);
        }

        return $file;
    }

    private function jsonInput(Request $request, string $key): array
    {
        $value = $request->input($key, '{}');

        if (is_array($value)) {
            return $value;
        }

        try {
            $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages([
                $key => ['The submitted mapping data is not valid JSON.'],
            ]);
        }

        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                $key => ['The submitted mapping data must be an object.'],
            ]);
        }

        return $decoded;
    }
}
