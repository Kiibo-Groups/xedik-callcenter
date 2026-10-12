<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Crea el tenant ITSAAR con roles y usuario admin.
 * Ejecutar SOLO en producción, después de php artisan migrate.
 *
 * php artisan db:seed --class=ItsaarTenantSeeder
 */
class ItsaarTenantSeeder extends Seeder
{
    public function run(): void
    {
        // Idempotente: no duplicar si ya existe
        if (DB::table('tenants')->where('slug', 'itsaar')->exists()) {
            $this->command->warn('Tenant ITSAAR ya existe — seeder omitido.');
            return;
        }

        $now = now();

        // 1. Crear tenant ITSAAR
        $tenantId = DB::table('tenants')->insertGetId([
            'name'       => 'ITSAAR',
            'slug'       => 'itsaar',
            'is_active'  => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->command->info("Tenant ITSAAR creado con id={$tenantId}");

        // 2. Crear roles para ITSAAR
        $adminRoleId = DB::table('roles')->insertGetId([
            'name'        => 'admin',
            'label'       => 'Admin',
            'tenant_id'   => $tenantId,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $agentRoleId = DB::table('roles')->insertGetId([
            'name'        => 'agent',
            'label'       => 'Agent',
            'tenant_id'   => $tenantId,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        DB::table('roles')->insert([
            'name'        => 'supervisor',
            'label'       => 'Supervisor',
            'tenant_id'   => $tenantId,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $this->command->info("Roles creados: admin={$adminRoleId}, agent={$agentRoleId}");

        // 3. Crear usuario Admin ITSAAR (jorge.zambada@itsaar.com.mx)
        // IMPORTANTE: cambiar la contraseña después del primer login
        $userId = DB::table('users')->insertGetId([
            'name'              => 'Jorge Zambada',
            'email'             => 'jorge.zambada@itsaar.com.mx',
            'password'          => Hash::make('Itsaar2026!'),
            'tenant_id'         => $tenantId,
            'role_id'           => $adminRoleId,
            'is_superadmin'     => false,
            'email_verified_at' => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        $this->command->info("Usuario jorge.zambada@itsaar.com.mx creado (id={$userId})");
        $this->command->warn('⚠️  Contraseña temporal: Itsaar2026! — cámbiala después del primer login');
        $this->command->line('');
        $this->command->line('Próximos pasos:');
        $this->command->line('  1. Login con jorge.zambada@itsaar.com.mx / Itsaar2026!');
        $this->command->line('  2. Configurar WhatsApp Account en Admin → WhatsApp Accounts');
        $this->command->line('  3. Cambiar contraseña');
    }
}
