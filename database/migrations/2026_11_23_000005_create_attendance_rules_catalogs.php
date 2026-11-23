<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paquete 3 (notas 3, 4 y 6 de Oscar): ventanas de horario por turno, catálogo de tipos de evento
 * e incidencias de checadas repetidas o fuera de orden en el resumen diario.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Ventanas de horario por turno (P6). Vacías = valores por omisión calculados del turno.
        Schema::table('work_shifts', function (Blueprint $table): void {
            $table->time('entry_window_start')->nullable()->after('exit_time');
            $table->time('entry_window_end')->nullable()->after('entry_window_start');
            $table->time('exit_window_start')->nullable()->after('entry_window_end');
            $table->time('exit_window_end')->nullable()->after('exit_window_start');
            $table->unsignedSmallInteger('repeat_threshold_minutes')->default(3)->after('exit_window_end');
        });

        // Catálogo de tipos de evento (P7): qué nombre recibe una entrada, salida o comida según sus minutos
        Schema::create('attendance_event_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->string('applies_to', 10); // entrada | salida | comida
            $table->integer('min_minutes')->default(0);
            $table->integer('max_minutes')->nullable(); // null = sin límite
            $table->string('color', 12)->default('secondary');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['applies_to', 'is_active']);
        });

        $now = now();
        $lstTipos = [
            ['a_tiempo', 'A tiempo', 'entrada', 0, 0, 'success', 10],
            ['retardo', 'Retardo', 'entrada', 1, 15, 'warning', 20],
            ['retardo_mayor', 'Retardo mayor', 'entrada', 16, null, 'danger', 30],
            ['salida_normal', 'Salida normal', 'salida', 0, 0, 'success', 40],
            ['salida_anticipada', 'Salida anticipada', 'salida', 1, null, 'danger', 50],
            ['comida_normal', 'Comida en tiempo', 'comida', 0, 0, 'success', 60],
            ['comida_excedida', 'Comida excedida', 'comida', 1, null, 'warning', 70],
        ];
        foreach ($lstTipos as [$code, $name, $appliesTo, $min, $max, $color, $order]) {
            DB::table('attendance_event_types')->insert([
                'code' => $code, 'name' => $name, 'applies_to' => $appliesTo,
                'min_minutes' => $min, 'max_minutes' => $max, 'color' => $color,
                'sort_order' => $order, 'is_system' => true, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        Schema::table('daily_summaries', function (Blueprint $table): void {
            $table->unsignedInteger('early_exit_minutes')->default(0)->after('late_minutes');
            $table->unsignedInteger('meal_exceeded_minutes')->default(0)->after('early_exit_minutes');
            $table->foreignId('entry_event_type_id')->nullable()->after('status')->constrained('attendance_event_types')->nullOnDelete();
            $table->foreignId('exit_event_type_id')->nullable()->after('entry_event_type_id')->constrained('attendance_event_types')->nullOnDelete();
            $table->foreignId('meal_event_type_id')->nullable()->after('exit_event_type_id')->constrained('attendance_event_types')->nullOnDelete();
            $table->json('incidents')->nullable()->after('meal_event_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('daily_summaries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('entry_event_type_id');
            $table->dropConstrainedForeignId('exit_event_type_id');
            $table->dropConstrainedForeignId('meal_event_type_id');
            $table->dropColumn(['early_exit_minutes', 'meal_exceeded_minutes', 'incidents']);
        });

        Schema::dropIfExists('attendance_event_types');

        Schema::table('work_shifts', function (Blueprint $table): void {
            $table->dropColumn(['entry_window_start', 'entry_window_end', 'exit_window_start', 'exit_window_end', 'repeat_threshold_minutes']);
        });
    }
};
