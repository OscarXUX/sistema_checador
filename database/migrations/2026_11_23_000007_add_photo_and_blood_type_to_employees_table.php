<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nota 16 / P2 de Oscar: foto y tipo de sangre en la ficha del empleado.
 * El tipo de sangre es un dato de salud (sensible, LFPDPPP): se guarda cifrado con APP_KEY.
 * La foto se guarda fuera de la carpeta pública (storage/app/private/employee-photos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('photo_path', 255)->nullable()->after('position');
            $table->text('blood_type')->nullable()->after('nss');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['photo_path', 'blood_type']);
        });
    }
};
