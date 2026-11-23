<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marking_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 80);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_biometric')->default(false);
            $table->boolean('requires_privacy_notice')->default(false);
            $table->string('privacy_notice_code', 32)->nullable();
            $table->boolean('device_supported')->nullable();
            $table->timestamp('last_detected_at')->nullable();
            $table->json('device_details')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('marking_methods')->insert([
            [
                'code' => 'fingerprint',
                'name' => 'Huella Dactilar',
                'is_enabled' => true,
                'is_biometric' => true,
                'requires_privacy_notice' => false,
                'privacy_notice_code' => null,
                'device_supported' => null,
                'last_detected_at' => null,
                'device_details' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'face',
                'name' => 'Reconocimiento Facial',
                'is_enabled' => false,
                'is_biometric' => true,
                'requires_privacy_notice' => true,
                'privacy_notice_code' => 'TE-02',
                'device_supported' => null,
                'last_detected_at' => null,
                'device_details' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'card',
                'name' => 'Tarjeta RFID',
                'is_enabled' => true,
                'is_biometric' => false,
                'requires_privacy_notice' => false,
                'privacy_notice_code' => null,
                'device_supported' => null,
                'last_detected_at' => null,
                'device_details' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'pin',
                'name' => 'PIN / Contraseña',
                'is_enabled' => true,
                'is_biometric' => false,
                'requires_privacy_notice' => false,
                'privacy_notice_code' => null,
                'device_supported' => null,
                'last_detected_at' => null,
                'device_details' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('marking_methods');
    }
};
