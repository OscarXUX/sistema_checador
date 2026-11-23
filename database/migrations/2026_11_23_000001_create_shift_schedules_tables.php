<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_rotations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('cycle_days')->default(7);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('shift_rotation_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shift_rotation_id')->constrained('shift_rotations')->cascadeOnDelete();
            $table->foreignId('work_shift_id')->constrained('work_shifts')->restrictOnDelete();
            $table->unsignedSmallInteger('step_order');
            $table->timestamps();

            $table->unique(['shift_rotation_id', 'step_order'], 'srs_rot_order_uq');
        });

        Schema::create('employee_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('employee_group_employee', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_group_id')->constrained('employee_groups')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['employee_group_id', 'employee_id'], 'ege_grp_emp_uq');
        });

        Schema::create('shift_rotation_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shift_rotation_id')->constrained('shift_rotations')->cascadeOnDelete();
            $table->string('target_type', 20);
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->foreignId('employee_group_id')->nullable()->constrained('employee_groups')->cascadeOnDelete();
            $table->string('department', 100)->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('start_step_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['target_type', 'employee_id'], 'sra_target_emp_idx');
            $table->index(['target_type', 'employee_group_id'], 'sra_target_grp_idx');
            $table->index(['target_type', 'department'], 'sra_target_dept_idx');
            $table->index(['starts_on', 'ends_on'], 'sra_dates_idx');
        });

        Schema::create('shift_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('work_shift_id')->constrained('work_shifts')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'starts_on', 'ends_on'], 'se_emp_dates_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_exceptions');
        Schema::dropIfExists('shift_rotation_assignments');
        Schema::dropIfExists('employee_group_employee');
        Schema::dropIfExists('employee_groups');
        Schema::dropIfExists('shift_rotation_steps');
        Schema::dropIfExists('shift_rotations');
    }
};
