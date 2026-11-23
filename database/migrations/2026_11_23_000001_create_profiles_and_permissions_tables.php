<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perfiles y permisos con la estructura del sistema de tickets (auditoría SE-02):
 * perfil con permisos + excepciones por persona (dar o negar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('description', 255)->nullable();
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('module', 40)->index();
            $table->string('description', 120);
            $table->string('details', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('profile_permission', function (Blueprint $table) {
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['profile_id', 'permission_id']);
        });

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->boolean('is_denied')->default(false);
            $table->primary(['user_id', 'permission_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('profile_id')->nullable()->after('email')->constrained('profiles')->restrictOnDelete();
        });

        Permissions::synchronize();

        $now = now();
        $adminId = DB::table('profiles')->insertGetId([
            'name' => 'Administrador',
            'description' => 'Todos los permisos y la administración de usuarios, perfiles y permisos.',
            'is_admin' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $queryId = DB::table('profiles')->insertGetId([
            'name' => 'Consulta',
            'description' => 'Solo consulta: dashboard, empleados (enmascarados), turnos y kardex.',
            'is_admin' => false,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $lstQueryPermissions = DB::table('permissions')->whereIn('key', Permissions::QUERY_PROFILE)->pluck('id');
        foreach ($lstQueryPermissions as $permissionId) {
            DB::table('profile_permission')->insert(['profile_id' => $queryId, 'permission_id' => $permissionId]);
        }

        // Las cuentas que ya existían siguen pudiendo hacer todo
        DB::table('users')->whereNull('profile_id')->update(['profile_id' => $adminId]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('profile_id');
        });

        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('profile_permission');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('profiles');
    }
};
