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
        Schema::create('work_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->time('entry_time');
            $table->time('exit_time');
            $table->unsignedSmallInteger('meal_duration_minutes')->default(60);
            $table->unsignedSmallInteger('tolerance_minutes')->default(10);
            $table->unsignedSmallInteger('max_weekly_hours')->default(48);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_shifts');
    }
};

