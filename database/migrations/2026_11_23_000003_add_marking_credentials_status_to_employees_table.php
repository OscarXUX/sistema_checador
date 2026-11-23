<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            // Solo bandera de configuración para PIN; el PIN en claro/hash NUNCA se guarda en la base (O3)
            $table->string('card_no', 32)->nullable()->after('fingerprint_enrolled')->index();
            $table->boolean('card_enrolled')->default(false)->after('card_no');
            $table->boolean('pin_configured')->default(false)->after('card_enrolled');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropIndex(['card_no']);
            $table->dropColumn(['card_no', 'card_enrolled', 'pin_configured']);
        });
    }
};
