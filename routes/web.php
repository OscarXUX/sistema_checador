<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\WorkShiftController;
use Illuminate\Support\Facades\Route;

// Dashboard Ejecutivo y Monitor en Vivo
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/dashboard/data', [DashboardController::class, 'data'])->name('dashboard.data');

// Estado y Sincronización ISAPI con Terminal Hikvision
Route::prefix('device')->name('device.')->group(function (): void {
    Route::get('/status', [DeviceController::class, 'status'])->name('status');
    Route::post('/sync', [DeviceController::class, 'sync'])->name('sync');
});

// Gestión de Empleados y Enrolamiento Biométrico
Route::prefix('employees')->name('employees.')->group(function (): void {
    Route::get('/', [EmployeeController::class, 'index'])->name('index');
    Route::get('/list', [EmployeeController::class, 'list'])->name('list');
    Route::post('/', [EmployeeController::class, 'store'])->name('store');
    Route::put('/{employee}', [EmployeeController::class, 'update'])->name('update');
    Route::delete('/{employee}', [EmployeeController::class, 'destroy'])->name('destroy');
    Route::post('/{employee}/sync-device', [EmployeeController::class, 'syncToDevice'])->name('sync-device');
    Route::delete('/{employee}/device', [EmployeeController::class, 'deleteFromDevice'])->name('delete-device');
    Route::post('/{employee}/capture-fingerprint', [EmployeeController::class, 'captureFingerprint'])->name('capture-fingerprint');
});

// Kardex Fiscal y Reportes Empresariales
Route::prefix('reports')->name('reports.')->group(function (): void {
    Route::get('/kardex', [ReportController::class, 'kardex'])->name('kardex');
    Route::get('/kardex/data', [ReportController::class, 'kardexData'])->name('kardex.data');
});

// Turnos Laborales y Reglas Fiscales
Route::prefix('shifts')->name('shifts.')->group(function (): void {
    Route::get('/', [WorkShiftController::class, 'index'])->name('index');
    Route::post('/', [WorkShiftController::class, 'store'])->name('store');
    Route::put('/{shift}', [WorkShiftController::class, 'update'])->name('update');
});
