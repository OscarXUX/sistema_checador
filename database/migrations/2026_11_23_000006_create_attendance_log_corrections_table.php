<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrección manual de una checada (propuesta 3.3): la checada original no se toca; aquí se guarda
 * qué debe contar (o que no cuenta), quién lo decidió y por qué.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_log_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attendance_log_id')->unique()->constrained('attendance_logs')->cascadeOnDelete();
            $table->string('corrected_type', 12); // check_in | break_out | break_in | check_out | ignore
            $table->string('reason', 255);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('daily_summaries', function (Blueprint $table): void {
            $table->unsignedSmallInteger('corrected_punches')->default(0)->after('incidents');
        });
    }

    public function down(): void
    {
        Schema::table('daily_summaries', function (Blueprint $table): void {
            $table->dropColumn('corrected_punches');
        });

        Schema::dropIfExists('attendance_log_corrections');
    }
};
