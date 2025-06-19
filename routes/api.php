<?php

use App\Http\Controllers\AcademicController;
use App\Http\Controllers\AcademicDegreeController;
use App\Http\Controllers\AcadmicSpaceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\HallController;
use App\Http\Controllers\LapController;
use App\Http\Controllers\LecturerController;
use App\Http\Controllers\ManagementRoleController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\ScheduleExportController;
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



Route::middleware('auth:sanctum')->group(function () {
    Route::resource('/acadmic-spaces', AcadmicSpaceController::class)
        ->middleware([
            'index'   => 'can:view academic spaces',
            'store'   => 'can:create academic space',
            'show'    => 'can:show academic space',
            'update'  => 'can:update academic space',
            'destroy' => 'can:delete academic space',
        ]);

        Route::resource('/departments', DepartmentController::class)
    ->middleware([
        'index'   => 'can:view departments',
        'store'   => 'can:create department',
        'show'    => 'can:show department',
        'update'  => 'can:update department',
        'destroy' => 'can:delete department',
    ]);


    Route::resource('/lecturers', LecturerController::class);
    
    
    Route::resource('/courses', CourseController::class)
    ->middleware([
        'index'   => 'can:view courses',
        'store'   => 'can:create course',
        'show'    => 'can:show course',
        'update'  => 'can:update course',
        'destroy' => 'can:delete course',
    ]);
    
    Route::resource('/academics', AcademicController::class);
    
    Route::post('/academics/add-course', [AcademicController::class, 'AddCourse'])
    ->middleware('can:add course to academic');
    
    Route::delete('/academics/remove-course/{id}', [AcademicController::class, 'RemoveCourse'])
    ->middleware('can:remove course from academic');
    
    Route::resource('/study-plans', StudyPlaneController::class);
    

    
    Route::get('/term-plans/show-item/{id}', [TermPlansController::class, 'ShowItem'])
    ->middleware('can:show term plan item');

    Route::post('/roles/assign' , [ManagementRoleController::class , 'assignRole'] )
    ->middleware('can:assign role');
    Route::post('/roles/remove' , [ManagementRoleController::class , 'removeRole'] )
    ->middleware('can:remove role');

    Route::post('/permission/give', [ManagementRoleController::class, 'assignPermission'])
    ->middleware('can:assign permission');

    Route::post('/permission/update', [ManagementRoleController::class, 'updatePermission'])
    ->middleware('can:remove permission');

    Route::resource('/halls' , HallController::class);
    Route::resource('/laps' , LapController::class);
    Route::get('/get-lecturers-ByType', [LecturerController::class, 'getStaffByType']);
    Route::get('/academic-degrees', [AcademicDegreeController::class, 'index']);

   
});
    Route::resource('/schedules' , ScheduleController::class);
    Route::get('/schedule/{id}/export-pdf', [ExportController::class, 'exportPdf']);
Route::get('/schedule/{id}/preview', [ExportController::class, 'previewSchedule']);

Route::get('/schedule/pdf', [ScheduleExportController::class, 'exportPdf']);

// تصدير عام (بدون تصفية)
Route::get('/export/pdf', [ScheduleExportController::class, 'exportPdf']);
Route::get('/export/excel', [ScheduleExportController::class, 'exportExcel']);

// تصدير جدول محاضر معين
Route::get('/export/pdf/lecturer/{id}', [ScheduleExportController::class, 'exportPdfLecturer']);
Route::get('/export/excel/lecturer/{id}', [ScheduleExportController::class, 'exportExcelLecturer']);

// تصدير جدول قاعة معينة
Route::get('/export/pdf/hall/{id}', [ScheduleExportController::class, 'exportPdfHall']);
Route::get('/export/excel/hall/{id}', [ScheduleExportController::class, 'exportExcelHall']);

// تصدير جدول معمل معين
Route::get('/export/pdf/lab/{id}', [ScheduleExportController::class, 'exportPdfLab']);
Route::get('/export/excel/lab/{id}', [ScheduleExportController::class, 'exportExcelLab']);




