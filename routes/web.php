<?php

use App\Http\Controllers\Admin\PermissionController as AdminPermissionController;
use App\Http\Controllers\Admin\ProfileController as AdminProfileController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Catalog\EventTypeController;
use App\Http\Controllers\Catalog\TimeWindowController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeePhotoController;
use App\Http\Controllers\MarkingMethodController;
use App\Http\Controllers\PunchCorrectionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ShiftScheduleController;
use App\Http\Controllers\WorkShiftController;
use Illuminate\Support\Facades\Route;

// Inicio de sesión (auditoría SE-01). Sin registro público: las cuentas se crean con php artisan usuarios:crear
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

// Todo lo demás requiere sesión, y cada ruta su permiso (can:clave de App\Support\Permissions).
// Una ruta nueva va dentro de este grupo con su permiso (lo revisa RouteProtectionTest).
Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/inicio', [AuthController::class, 'home'])->name('home');

    // Dashboard Ejecutivo y Monitor en Vivo
    Route::get('/', [DashboardController::class, 'index'])->middleware('can:dashboard.consultar')->name('dashboard');
    Route::get('/dashboard/data', [DashboardController::class, 'data'])->middleware('can:dashboard.consultar')->name('dashboard.data');

    // Estado y Sincronización ISAPI con Terminal Hikvision
    Route::prefix('device')->name('device.')->group(function (): void {
        Route::get('/status', [DeviceController::class, 'status'])->middleware('throttle:device-status')->name('status');
        Route::post('/sync', [DeviceController::class, 'sync'])->middleware(['throttle:device', 'can:terminal.sincronizar'])->name('sync');
    });

    // Gestión de Empleados y Enrolamiento Biométrico
    Route::prefix('employees')->name('employees.')->group(function (): void {
        Route::get('/', [EmployeeController::class, 'index'])->middleware('can:empleados.consultar')->name('index');
        Route::get('/list', [EmployeeController::class, 'list'])->middleware('can:empleados.consultar')->name('list');
        Route::post('/', [EmployeeController::class, 'store'])->middleware('can:empleados.agregar')->name('store');
        // Foto (nota 16): privada, se entrega solo por esta ruta
        Route::get('/{employee}/photo', [EmployeePhotoController::class, 'show'])->middleware('can:empleados.consultar')->name('photo');
        Route::post('/{employee}/photo', [EmployeePhotoController::class, 'store'])->middleware('can:empleados.actualizar')->name('photo.store');
        Route::delete('/{employee}/photo', [EmployeePhotoController::class, 'destroy'])->middleware('can:empleados.actualizar')->name('photo.destroy');
        Route::get('/{employee}', [EmployeeController::class, 'show'])->middleware('can:empleados.actualizar')->name('show');
        Route::put('/{employee}', [EmployeeController::class, 'update'])->middleware('can:empleados.actualizar')->name('update');
        Route::delete('/{employee}', [EmployeeController::class, 'destroy'])->middleware('can:empleados.baja')->name('destroy');
        Route::middleware('throttle:device')->group(function (): void {
            Route::post('/{employee}/sync-device', [EmployeeController::class, 'syncToDevice'])->middleware('can:terminal.usuarios')->name('sync-device');
            Route::delete('/{employee}/device', [EmployeeController::class, 'deleteFromDevice'])->middleware('can:terminal.usuarios')->name('delete-device');
            Route::post('/{employee}/capture-fingerprint', [EmployeeController::class, 'captureFingerprint'])->middleware('can:terminal.huellas')->name('capture-fingerprint');
            Route::post('/{employee}/enroll-card', [EmployeeController::class, 'enrollCard'])->middleware('can:terminal.usuarios')->name('enroll-card');
            Route::post('/{employee}/enroll-pin', [EmployeeController::class, 'enrollPin'])->middleware('can:terminal.usuarios')->name('enroll-pin');
            Route::post('/{employee}/enroll-face', [EmployeeController::class, 'enrollFace'])->middleware('can:terminal.huellas')->name('enroll-face');
        });
    });

    // Kardex Fiscal y Reportes Empresariales
    Route::prefix('reports')->name('reports.')->middleware(['throttle:reports', 'can:kardex.consultar'])->group(function (): void {
        Route::get('/kardex', [ReportController::class, 'kardex'])->name('kardex');
        Route::get('/kardex/data', [ReportController::class, 'kardexData'])->name('kardex.data');
        Route::get('/kardex/export/xlsx', [ReportController::class, 'exportXlsx'])->middleware('can:kardex.exportar')->name('kardex.export.xlsx');
        Route::get('/kardex/logs/{attendanceLog}/payload', [ReportController::class, 'logPayload'])->name('kardex.payload');
        // Checadas de un día y su corrección manual (la checada original no se modifica)
        Route::get('/kardex/summaries/{dailySummary}/punches', [PunchCorrectionController::class, 'day'])->name('kardex.punches');
        Route::post('/kardex/punches/{attendanceLog}/correction', [PunchCorrectionController::class, 'store'])->middleware('can:kardex.corregir')->name('kardex.correction.store');
        Route::delete('/kardex/punches/{attendanceLog}/correction', [PunchCorrectionController::class, 'destroy'])->middleware('can:kardex.corregir')->name('kardex.correction.destroy');
    });

    // Administración: usuarios, perfiles y catálogo de permisos (solo el perfil administrador)
    Route::prefix('administracion')->name('admin.')->middleware('can:administrar')->group(function (): void {
        Route::get('/usuarios', [AdminUserController::class, 'index'])->name('users');
        Route::post('/usuarios', [AdminUserController::class, 'store'])->name('users.store');
        Route::get('/usuarios/{user}', [AdminUserController::class, 'edit'])->name('users.edit');
        Route::put('/usuarios/{user}', [AdminUserController::class, 'update'])->name('users.update');
        Route::put('/usuarios/{user}/permisos', [AdminUserController::class, 'permissions'])->name('users.permissions');

        Route::get('/perfiles', [AdminProfileController::class, 'index'])->name('profiles');
        Route::post('/perfiles', [AdminProfileController::class, 'store'])->name('profiles.store');
        Route::get('/perfiles/{profile}', [AdminProfileController::class, 'edit'])->name('profiles.edit');
        Route::put('/perfiles/{profile}', [AdminProfileController::class, 'update'])->name('profiles.update');
        Route::delete('/perfiles/{profile}', [AdminProfileController::class, 'destroy'])->name('profiles.destroy');

        Route::get('/permisos', [AdminPermissionController::class, 'index'])->name('permissions');
        Route::put('/permisos/{permission}', [AdminPermissionController::class, 'update'])->name('permissions.update');
    });

    // Catálogos de reglas de asistencia (paquete 3: ventanas de horario y tipos de evento)
    Route::prefix('catalogos')->name('catalogs.')->group(function (): void {
        Route::get('/ventanas', [TimeWindowController::class, 'index'])->middleware('can:catalogos.consultar')->name('windows');
        Route::put('/ventanas/{shift}', [TimeWindowController::class, 'update'])->middleware('can:catalogos.administrar')->name('windows.update');

        Route::get('/tipos-evento', [EventTypeController::class, 'index'])->middleware('can:catalogos.consultar')->name('event-types');
        Route::post('/tipos-evento', [EventTypeController::class, 'store'])->middleware('can:catalogos.administrar')->name('event-types.store');
        Route::put('/tipos-evento/{eventType}', [EventTypeController::class, 'update'])->middleware('can:catalogos.administrar')->name('event-types.update');
        Route::delete('/tipos-evento/{eventType}', [EventTypeController::class, 'destroy'])->middleware('can:catalogos.administrar')->name('event-types.destroy');
    });

    // Turnos Laborales, Rotaciones y Excepciones
    Route::prefix('shifts')->name('shifts.')->group(function (): void {
        Route::get('/', [WorkShiftController::class, 'index'])->middleware('can:turnos.consultar')->name('index');
        Route::post('/', [WorkShiftController::class, 'store'])->middleware('can:turnos.administrar')->name('store');
        Route::put('/{shift}', [WorkShiftController::class, 'update'])->middleware('can:turnos.administrar')->name('update')->whereNumber('shift');

        Route::middleware('can:turnos.programar')->group(function (): void {
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
    });

    // Medios de Marcaje e Inspección ISAPI (O3)
    Route::prefix('marking-methods')->name('marking-methods.')->group(function (): void {
        Route::get('/', [MarkingMethodController::class, 'index'])->middleware('can:medios.consultar')->name('index');
        Route::post('/detect', [MarkingMethodController::class, 'detect'])->middleware(['throttle:device', 'can:medios.administrar'])->name('detect');
        Route::patch('/{markingMethod}', [MarkingMethodController::class, 'update'])->middleware('can:medios.administrar')->name('update');
    });
});
