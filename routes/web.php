<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\MarkingMethodController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ShiftScheduleController;
use App\Http\Controllers\WorkShiftController;
use Illuminate\Support\Facades\Route;

// Inicio de sesión (auditoría SE-01). Sin registro público: las cuentas se crean con php artisan usuarios:crear
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

// Todo lo demás requiere sesión. Una ruta nueva va dentro de este grupo (lo revisa RouteProtectionTest).
Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

    // Dashboard Ejecutivo y Monitor en Vivo
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/data', [DashboardController::class, 'data'])->name('dashboard.data');

    // Estado y Sincronización ISAPI con Terminal Hikvision
    Route::prefix('device')->name('device.')->group(function (): void {
        Route::get('/status', [DeviceController::class, 'status'])->middleware('throttle:device-status')->name('status');
        Route::post('/sync', [DeviceController::class, 'sync'])->middleware('throttle:device')->name('sync');
    });

    // Gestión de Empleados y Enrolamiento Biométrico
    Route::prefix('employees')->name('employees.')->group(function (): void {
        Route::get('/', [EmployeeController::class, 'index'])->name('index');
        Route::get('/list', [EmployeeController::class, 'list'])->name('list');
        Route::post('/', [EmployeeController::class, 'store'])->name('store');
        Route::get('/{employee}', [EmployeeController::class, 'show'])->name('show');
        Route::put('/{employee}', [EmployeeController::class, 'update'])->name('update');
        Route::delete('/{employee}', [EmployeeController::class, 'destroy'])->name('destroy');
        Route::middleware('throttle:device')->group(function (): void {
            Route::post('/{employee}/sync-device', [EmployeeController::class, 'syncToDevice'])->name('sync-device');
            Route::delete('/{employee}/device', [EmployeeController::class, 'deleteFromDevice'])->name('delete-device');
            Route::post('/{employee}/capture-fingerprint', [EmployeeController::class, 'captureFingerprint'])->name('capture-fingerprint');
            Route::post('/{employee}/enroll-card', [EmployeeController::class, 'enrollCard'])->name('enroll-card');
            Route::post('/{employee}/enroll-pin', [EmployeeController::class, 'enrollPin'])->name('enroll-pin');
            Route::post('/{employee}/enroll-face', [EmployeeController::class, 'enrollFace'])->name('enroll-face');
        });
    });

    // Kardex Fiscal y Reportes Empresariales
    Route::prefix('reports')->name('reports.')->middleware('throttle:reports')->group(function (): void {
        Route::get('/kardex', [ReportController::class, 'kardex'])->name('kardex');
        Route::get('/kardex/data', [ReportController::class, 'kardexData'])->name('kardex.data');
        Route::get('/kardex/export/xlsx', [ReportController::class, 'exportXlsx'])->name('kardex.export.xlsx');
        Route::get('/kardex/logs/{attendanceLog}/payload', [ReportController::class, 'logPayload'])->name('kardex.payload');
    });

    // Turnos Laborales, Rotaciones y Excepciones
    Route::prefix('shifts')->name('shifts.')->group(function (): void {
        Route::get('/', [WorkShiftController::class, 'index'])->name('index');
        Route::post('/', [WorkShiftController::class, 'store'])->name('store');
        Route::put('/{shift}', [WorkShiftController::class, 'update'])->name('update')->whereNumber('shift');

        Route::post('/rotations', [ShiftScheduleController::class, 'storeRotation'])->name('rotations.store');
        Route::put('/rotations/{rotation}', [ShiftScheduleController::class, 'updateRotation'])->name('rotations.update');
        Route::delete('/rotations/{rotation}', [ShiftScheduleController::class, 'destroyRotation'])->name('rotations.destroy');

        Route::post('/groups', [ShiftScheduleController::class, 'storeGroup'])->name('groups.store');
        Route::put('/groups/{group}', [ShiftScheduleController::class, 'updateGroup'])->name('groups.update');
        Route::delete('/groups/{group}', [ShiftScheduleController::class, 'destroyGroup'])->name('groups.destroy');

        Route::post('/assignments', [ShiftScheduleController::class, 'storeAssignment'])->name('assignments.store');
        Route::delete('/assignments/{assignment}', [ShiftScheduleController::class, 'destroyAssignment'])->name('assignments.destroy');

        Route::post('/exceptions', [ShiftScheduleController::class, 'storeException'])->name('exceptions.store');
        Route::delete('/exceptions/{exception}', [ShiftScheduleController::class, 'destroyException'])->name('exceptions.destroy');
    });

    // Medios de Marcaje e Inspección ISAPI (O3)
    Route::prefix('marking-methods')->name('marking-methods.')->group(function (): void {
        Route::get('/', [MarkingMethodController::class, 'index'])->name('index');
        Route::post('/detect', [MarkingMethodController::class, 'detect'])->middleware('throttle:device')->name('detect');
        Route::patch('/{markingMethod}', [MarkingMethodController::class, 'update'])->name('update');
    });
});
