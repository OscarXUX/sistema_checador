<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')
                ->constrained('employees')
                ->cascadeOnDelete();
            $table->string('device_ip', 45);
            $table->dateTime('punched_at')->index();
            $table->enum('event_type', [
                'check_in',
                'break_out',
                'break_in',
                'check_out',
                'unclassified',
            ])->default('unclassified');
            $table->string('verification_mode', 32)->default('fingerprint');
            $table->json('raw_payload');
            $table->timestamps();

            $table->unique(['employee_id', 'punched_at'], 'attendance_logs_employee_punched_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};

