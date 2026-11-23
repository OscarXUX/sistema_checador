<?php

use App\Support\PersonalData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RFC, CURP y NSS cifrados en la base, con índice ciego para buscar RFC o CURP exactos (auditoría SE-05).
 *
 * El cifrado usa APP_KEY: si se cambia la llave sin volver a cifrar, estos datos ya no se pueden leer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropIndex(['rfc']);
            $table->dropIndex(['curp']);
        });

        Schema::table('employees', function (Blueprint $table): void {
            // El texto cifrado mide ~200 caracteres
            $table->text('rfc')->nullable()->change();
            $table->text('curp')->nullable()->change();
            $table->text('nss')->nullable()->change();
            $table->char('rfc_hash', 64)->nullable()->index()->after('rfc');
            $table->char('curp_hash', 64)->nullable()->index()->after('curp');
        });

        // Cifrar lo que ya existe
        DB::table('employees')->select(['id', 'rfc', 'curp', 'nss'])->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('employees')->where('id', $row->id)->update([
                    'rfc' => $row->rfc !== null && $row->rfc !== '' ? Crypt::encryptString($row->rfc) : null,
                    'curp' => $row->curp !== null && $row->curp !== '' ? Crypt::encryptString($row->curp) : null,
                    'nss' => $row->nss !== null && $row->nss !== '' ? Crypt::encryptString($row->nss) : null,
                    'rfc_hash' => PersonalData::blindIndex($row->rfc),
                    'curp_hash' => PersonalData::blindIndex($row->curp),
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::table('employees')->select(['id', 'rfc', 'curp', 'nss'])->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('employees')->where('id', $row->id)->update([
                    'rfc' => $row->rfc !== null ? Crypt::decryptString($row->rfc) : null,
                    'curp' => $row->curp !== null ? Crypt::decryptString($row->curp) : null,
                    'nss' => $row->nss !== null ? Crypt::decryptString($row->nss) : null,
                ]);
            }
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropIndex(['rfc_hash']);
            $table->dropIndex(['curp_hash']);
            $table->dropColumn(['rfc_hash', 'curp_hash']);
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->string('rfc', 13)->nullable()->change();
            $table->string('curp', 18)->nullable()->change();
            $table->string('nss', 11)->nullable()->change();
            $table->index('rfc');
            $table->index('curp');
        });
    }
};
