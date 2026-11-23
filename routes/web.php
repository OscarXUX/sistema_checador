<?php

use App\Http\Controllers\Admin\PermissionController as AdminPermissionController;
use App\Http\Controllers\Admin\ProfileController as AdminProfileController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ReportController;
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
        Route::get('/{employee}', [EmployeeController::class, 'show'])->middleware('can:empleados.actualizar')->name('show');
        Route::put('/{employee}', [EmployeeController::class, 'update'])->middleware('can:empleados.actualizar')->name('update');
        Route::delete('/{employee}', [EmployeeController::class, 'destroy'])->middleware('can:empleados.baja')->name('destroy');
        Route::middleware('throttle:device')->group(function (): void {
            Route::post('/{employee}/sync-device', [EmployeeController::class, 'syncToDevice'])->middleware('can:terminal.usuarios')->name('sync-device');
            Route::delete('/{employee}/device', [EmployeeController::class, 'deleteFromDevice'])->middleware('can:terminal.usuarios')->name('delete-device');
            Route::post('/{employee}/capture-fingerprint', [EmployeeController::class, 'captureFingerprint'])->middleware('can:terminal.huellas')->name('capture-fingerprint');
        });
    });

    // Kardex Fiscal y Reportes Empresariales
    Route::prefix('reports')->name('reports.')->middleware(['throttle:reports', 'can:kardex.consultar'])->group(function (): void {
        Route::get('/kardex', [ReportController::class, 'kardex'])->name('kardex');
        Route::get('/kardex/data', [ReportController::class, 'kardexData'])->name('kardex.data');
        Route::get('/kardex/logs/{attendanceLog}/payload', [ReportController::class, 'logPayload'])->name('kardex.payload');
    });

    // Turnos Laborales y Reglas Fiscales
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

    Route::prefix('shifts')->name('shifts.')->group(function (): void {
        Route::get('/', [WorkShiftController::class, 'index'])->middleware('can:turnos.consultar')->name('index');
        Route::post('/', [WorkShiftController::class, 'store'])->middleware('can:turnos.administrar')->name('store');
        Route::put('/{shift}', [WorkShiftController::class, 'update'])->middleware('can:turnos.administrar')->name('update');
    });
});
