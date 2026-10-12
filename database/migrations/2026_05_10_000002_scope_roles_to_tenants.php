<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convierte roles de globales a scoped por tenant.
 *
 * Antes: roles.name UNIQUE — un "admin" global para toda la app
 * Después: roles.(name, tenant_id) UNIQUE — cada tenant tiene su "admin"
 *
 * Backfill:
 *  - Roles existentes → tenant_id = 1
 *  - Para cada otro tenant que exista → duplicar todos los roles y
 *    reasignar users.role_id de ese tenant a sus nuevas copias
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // ── 1. Agregar tenant_id nullable a roles ─────────────────────────
        if (!Schema::hasColumn('roles', 'tenant_id')) {
            if ($driver === 'sqlite') {
                DB::statement('ALTER TABLE roles ADD COLUMN tenant_id INTEGER DEFAULT NULL');
            } else {
                Schema::table('roles', function (Blueprint $table) {
                    $table->foreignId('tenant_id')
                          ->nullable()
                          ->after('id')
                          ->constrained('tenants')
                          ->cascadeOnDelete();
                });
            }
        }

        // ── 2. Eliminar unique(name) ANTES de insertar duplicados ─────────
        // Necesario para poder tener admin en tenant 1 Y admin en tenant 2
        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS roles_name_unique');
        } else {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropUnique('roles_name_unique');
            });
        }

        // ── 3. Backfill: roles existentes → tenant 1 ──────────────────────
        DB::table('roles')->whereNull('tenant_id')->update(['tenant_id' => 1]);

        // ── 4. Duplicar roles para cada tenant adicional ───────────────────
        $tenants = DB::table('tenants')->where('id', '!=', 1)->pluck('id');

        foreach ($tenants as $tenantId) {
            $existingRoles = DB::table('roles')->where('tenant_id', 1)->get();

            foreach ($existingRoles as $sourceRole) {
                $alreadyExists = DB::table('roles')
                    ->where('name', $sourceRole->name)
                    ->where('tenant_id', $tenantId)
                    ->exists();

                if ($alreadyExists) {
                    continue;
                }

                $newRoleId = DB::table('roles')->insertGetId([
                    'name'       => $sourceRole->name,
                    'label'      => $sourceRole->label,
                    'tenant_id'  => $tenantId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Copiar permisos del rol origen al nuevo
                $permissionIds = DB::table('role_permissions')
                    ->where('role_id', $sourceRole->id)
                    ->pluck('permission_id');

                foreach ($permissionIds as $permId) {
                    DB::table('role_permissions')->insert([
                        'role_id'       => $newRoleId,
                        'permission_id' => $permId,
                    ]);
                }

                // Reasignar users de ese tenant que tenían el rol origen
                DB::table('users')
                    ->where('tenant_id', $tenantId)
                    ->where('role_id', $sourceRole->id)
                    ->update(['role_id' => $newRoleId]);
            }
        }

        // ── 5. NOT NULL en MySQL ───────────────────────────────────────────
        if ($driver !== 'sqlite') {
            Schema::table('roles', function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable(false)->change();
            });
        }

        // ── 6. Agregar unique(name, tenant_id) ────────────────────────────
        if ($driver === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS roles_name_tenant_unique ON roles(name, tenant_id)');
        } else {
            Schema::table('roles', function (Blueprint $table) {
                $table->unique(['name', 'tenant_id'], 'roles_name_tenant_unique');
            });
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        // Revertir unique compuesto → unique simple
        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS roles_name_tenant_unique');
        } else {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropUnique('roles_name_tenant_unique');
            });
        }

        // Eliminar roles duplicados (mantener solo tenant_id = 1)
        // Reasignar usuarios de otros tenants al role de tenant 1
        $extraRoles = DB::table('roles')->where('tenant_id', '!=', 1)->get();
        foreach ($extraRoles as $extraRole) {
            $baseRole = DB::table('roles')
                ->where('name', $extraRole->name)
                ->where('tenant_id', 1)
                ->first();
            if ($baseRole) {
                DB::table('users')
                    ->where('role_id', $extraRole->id)
                    ->update(['role_id' => $baseRole->id]);
            }
            DB::table('role_permissions')->where('role_id', $extraRole->id)->delete();
            DB::table('roles')->where('id', $extraRole->id)->delete();
        }

        // Restaurar unique(name) simple
        if ($driver === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS roles_name_unique ON roles(name)');
        } else {
            Schema::table('roles', function (Blueprint $table) {
                $table->unique('name', 'roles_name_unique');
            });
        }

        // Quitar tenant_id de roles
        if ($driver !== 'sqlite') {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        } else {
            // SQLite: no se puede drop column fácilmente, dejar como está
        }
    }
};
