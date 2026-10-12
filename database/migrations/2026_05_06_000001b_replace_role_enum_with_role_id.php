<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Agregar roles de tenant ────────────────────────────────────
        // superadmin NO va aquí — eso es la bandera is_superadmin en users.
        // La tabla roles solo contiene roles que operan DENTRO de un tenant.
        $existing = DB::table('roles')->pluck('name')->toArray();

        $newRoles = [
            ['name' => 'admin', 'label' => 'Admin'],
            ['name' => 'agent', 'label' => 'Agent'],
            ['name' => 'supervisor', 'label' => 'Supervisor'],
            // Agrega más roles de tenant aquí en el futuro:
            // ['name' => 'quality', 'label' => 'Quality Analyst'],
        ];

        foreach ($newRoles as $role) {
            if (!in_array($role['name'], $existing)) {
                DB::table('roles')->insert(array_merge($role, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }

        // ── 2. Agregar role_id (nullable primero) ─────────────────────────
        if (DB::getDriverName() === 'sqlite') {
            // SQLite is used for functional tests and would rebuild this legacy
            // table when adding FK/NOT NULL changes. The historical enum/default
            // definition on users.role is not rebuild-safe in SQLite, so this
            // branch adds only the nullable column. MariaDB remains the production
            // constraint reference and follows the full FK + NOT NULL path below.
            if (!Schema::hasColumn('users', 'role_id')) {
                DB::statement('ALTER TABLE users ADD COLUMN role_id INTEGER NULL');
            }
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('role_id')
                    ->nullable()
                    ->after('role')
                    ->constrained('roles')
                    ->restrictOnDelete();
            });
        }

        // ── 3. Backfill: string → id ──────────────────────────────────────
        $roleIds = DB::table('roles')->pluck('id', 'name');

        DB::table('users')
            ->where('role', 'admin')
            ->update(['role_id' => $roleIds['admin']]);

        DB::table('users')
            ->where('role', 'agent')
            ->update(['role_id' => $roleIds['agent']]);

        // Cualquier usuario sin role_id (incluido is_superadmin=1) → admin
        // El superadmin usa is_superadmin=1 para sus privilegios especiales,
        // no el role_id. Pero no puede quedar NULL.
        DB::table('users')
            ->whereNull('role_id')
            ->update(['role_id' => $roleIds['admin']]);

        // ── 4. role_id NOT NULL ───────────────────────────────────────────
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('role_id')->nullable(false)->change();
            });
        }

        // ── 5. Eliminar campo role string — adiós enum ────────────────────
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }
    }

    public function down(): void
    {
        // Restaurar role string
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('agent')->after('password');
        });

        // Backfill inverso
        $roles = DB::table('roles')->pluck('name', 'id');
        foreach ($roles as $id => $name) {
            DB::table('users')
                ->where('role_id', $id)
                ->update(['role' => in_array($name, ['admin', 'agent']) ? $name : 'agent']);
        }

        // Eliminar role_id
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');
        });

        // Eliminar solo supervisor (no admin ni agent que ya existían)
        DB::table('roles')->where('name', 'supervisor')->delete();
    }
};
