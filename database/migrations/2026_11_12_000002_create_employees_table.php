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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('hikvision_employee_no', 32)->unique();
            $table->string('full_name', 150);
            $table->string('rfc', 13)->nullable()->index();
            $table->string('curp', 18)->nullable()->index();
            $table->string('nss', 11)->nullable();
            $table->string('department', 100)->index();
            $table->string('position', 100);
            $table->foreignId('work_shift_id')
                ->constrained('work_shifts')
                ->restrictOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('fingerprint_enrolled')->default(false);
            $table->timestamp('device_synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};

