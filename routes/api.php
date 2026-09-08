<?php

use App\Http\Controllers\AcademicController;
use App\Http\Controllers\AcademicDegreeController;
use App\Http\Controllers\AcadmicSpaceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\HallController;
use App\Http\Controllers\LapController;
use App\Http\Controllers\LecturerController;
use App\Http\Controllers\ManagementRoleController;
use App\Http\Controllers\RegulationImportController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\ScheduleExportController;
use App\Http\Controllers\ScheduleSettingsController;
use App\Http\Controllers\ScriptController;
use App\Http\Controllers\StudyPlaneController;
use App\Http\Controllers\TermPlansController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::controller(AuthController::class)->group(function () {

    // 🔹 تسجيل الدخول
    Route::post('/login', 'login');

    // 🔹 العمليات المحمية (تحتاج توكين)
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', 'getUser');  // جلب بيانات المستخدم الحالي
        Route::post('/register', 'register'); // تسجيل مستخدم جديد (Admin فقط)
        Route::post('/logout', 'logout'); // تسجيل الخروج
    });

});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::resource('/acadmic-spaces', AcadmicSpaceController::class)
    ->middleware([
        'index' => 'can:view academic spaces',
        'store' => 'can:create academic space',
        'show' => 'can:show academic space',
        'update' => 'can:update academic space',
        'destroy' => 'can:delete academic space',
    ]);

Route::resource('/departments', DepartmentController::class);

Route::delete('/lecturers/bulk', [LecturerController::class, 'bulkDestroy']);
Route::resource('/lecturers', LecturerController::class);

    Route::resource('/courses', CourseController::class)
    ->middleware([
        'index' => 'can:view courses',
        'store' => 'can:create course',
        'show' => 'can:show course',
        'update' => 'can:update course',
        'destroy' => 'can:delete course',
    ]);

Route::delete('/academics/bulk', [AcademicController::class, 'bulkDestroy']);
Route::resource('/academics', AcademicController::class);

Route::post('/academics/add-course', [AcademicController::class, 'AddCourse'])
    ->middleware('can:add course to academic');

Route::delete('/academics/remove-course/{id}', [AcademicController::class, 'RemoveCourse'])
    ->middleware('can:remove course from academic');

Route::delete('/study-plans/bulk', [StudyPlaneController::class, 'bulkDestroy']);
Route::resource('/study-plans', StudyPlaneController::class);

Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::post('/regulation-imports/preview', [RegulationImportController::class, 'preview']);
    Route::post('/regulation-imports', [RegulationImportController::class, 'store']);
    Route::post('/regulation-imports/repair', [RegulationImportController::class, 'repair']);
});

    Route::get('/term-plans/show-item/{id}', [TermPlansController::class, 'ShowItem'])
    ->middleware('can:show term plan item');

Route::post('/roles/assign', [ManagementRoleController::class, 'assignRole'])
    ->middleware('can:assign role');
Route::post('/roles/remove', [ManagementRoleController::class, 'removeRole'])
    ->middleware('can:remove role');

Route::post('/permission/give', [ManagementRoleController::class, 'assignPermission'])
    ->middleware('can:assign permission');

Route::post('/permission/update', [ManagementRoleController::class, 'updatePermission'])
    ->middleware('can:remove permission');

Route::delete('/halls/bulk', [HallController::class, 'bulkDestroy']);
Route::resource('/halls', HallController::class);
Route::delete('/laps/bulk', [LapController::class, 'bulkDestroy']);
Route::resource('/laps', LapController::class);
Route::get('/get-lecturers-ByType', [LecturerController::class, 'getStaffByType']);
Route::get('/academic-degrees', [AcademicDegreeController::class, 'index']);

Route::get('/schedules/{id}/generation-context', [ScheduleController::class, 'generationContext']);
Route::resource('/schedules', ScheduleController::class);
Route::patch('/schedules/{schedule}/entries/{entry}', [EntryController::class, 'update']);
Route::post('/schedules/{schedule}/entries/{entry}/swap', [EntryController::class, 'swap']);
Route::get('/schedule-settings', [ScheduleSettingsController::class, 'show']);
Route::put('/schedule-settings', [ScheduleSettingsController::class, 'update'])
    ->middleware(['auth:sanctum', 'can:manage schedule settings']);
Route::get('/schedule/{id}/export-pdf', [ExportController::class, 'exportPdf']);
Route::get('/schedule/{id}/preview', [ExportController::class, 'previewSchedule']);

Route::post('/script/run', [ScriptController::class, 'run']);

// روابط التصدير مع الفلاتر المتعددة
Route::post('/schedule/export/pdf', [ScheduleExportController::class, 'exportPdfWithFilters'])
    ->name('export.pdf');

Route::post('/schedule/export/excel', [ScheduleExportController::class, 'exportExcelWithFilters'])
    ->name('export.excel');

Route::post('/schedule/export/excel/full', [ScheduleExportController::class, 'exportFullExcel'])
    ->name('export.excel.full');
