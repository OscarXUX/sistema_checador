<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evita que borrar un empleado elimine en cascada su bitácora de checadas
 * y su resumen diario (auditoría SE-03). Un empleado con historial se da de baja
 * de forma lógica (is_active = false) desde EmployeeController::destroy().
 */
return new class extends Migration
{
    private const TABLES = ['attendance_logs', 'daily_summaries'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['employee_id']);
                $table->foreign('employee_id')
                    ->references('id')
                    ->on('employees')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['employee_id']);
                $table->foreign('employee_id')
                    ->references('id')
                    ->on('employees')
                    ->cascadeOnDelete();
            });
        }
    }
};
