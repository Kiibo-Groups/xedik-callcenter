<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    private array $statuses = [
        ['name' => 'New',         'slug' => 'new',         'color' => '#0891b2', 'bg_color' => '#cffafe', 'order' => 1, 'is_default' => 1, 'is_active' => 1],
        ['name' => 'Contacted',   'slug' => 'contacted',   'color' => '#4f46e5', 'bg_color' => '#e0e7ff', 'order' => 2, 'is_default' => 0, 'is_active' => 1],
        ['name' => 'Qualified',   'slug' => 'qualified',   'color' => '#b45309', 'bg_color' => '#fef9c3', 'order' => 3, 'is_default' => 0, 'is_active' => 1],
        ['name' => 'Proposal',    'slug' => 'proposal',    'color' => '#15803d', 'bg_color' => '#dcfce7', 'order' => 4, 'is_default' => 0, 'is_active' => 1],
        ['name' => 'Negotiation', 'slug' => 'negotiation', 'color' => '#e2e8f0', 'bg_color' => '#1e293b', 'order' => 5, 'is_default' => 0, 'is_active' => 1],
        ['name' => 'Closed Won',  'slug' => 'closed_won',  'color' => '#16a34a', 'bg_color' => '#dcfce7', 'order' => 6, 'is_default' => 0, 'is_active' => 1],
        ['name' => 'Closed Lost', 'slug' => 'closed_lost', 'color' => '#dc2626', 'bg_color' => '#fee2e2', 'order' => 7, 'is_default' => 0, 'is_active' => 1],
    ];

    private array $sources = [
        ['name' => 'Web',          'is_active' => 1, 'order' => 1],
        ['name' => 'Phone',        'is_active' => 1, 'order' => 2],
        ['name' => 'Dialer',       'is_active' => 1, 'order' => 3],
        ['name' => 'Referral',     'is_active' => 1, 'order' => 4],
        ['name' => 'Email',        'is_active' => 1, 'order' => 5],
        ['name' => 'Social Media', 'is_active' => 1, 'order' => 6],
        ['name' => 'Other',        'is_active' => 1, 'order' => 7],
    ];

    public function up(): void
    {
        $driver = DB::getDriverName();

        // ── Fix unique constraints: slug → (tenant_id, slug); name → (tenant_id, name) ──
        // SQLite no soporta DROP CONSTRAINT ni ADD UNIQUE en tabla existente.
        // Se recrea la tabla con los índices correctos.
        if ($driver === 'sqlite') {
            $this->fixUniqueConstraintsSqlite();
        } else {
            $this->fixUniqueConstraintsMySQL();
        }

        // ── Seed statuses/sources para todos los tenants que no los tengan ──
        $tenants = DB::table('tenants')->pluck('id');
        $now     = now();

        foreach ($tenants as $tenantId) {
            foreach ($this->statuses as $status) {
                $exists = DB::table('lead_statuses')
                    ->where('tenant_id', $tenantId)
                    ->where('slug', $status['slug'])
                    ->exists();

                if (!$exists) {
                    DB::table('lead_statuses')->insert(array_merge($status, [
                        'tenant_id'  => $tenantId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]));
                }
            }

            foreach ($this->sources as $source) {
                $exists = DB::table('lead_sources')
                    ->where('tenant_id', $tenantId)
                    ->where('name', $source['name'])
                    ->exists();

                if (!$exists) {
                    DB::table('lead_sources')->insert(array_merge($source, [
                        'tenant_id'  => $tenantId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]));
                }
            }
        }
    }

    /**
     * SQLite: recrea las tablas para cambiar el índice único.
     * Técnica estándar porque SQLite no soporta ALTER TABLE DROP CONSTRAINT.
     */
    private function fixUniqueConstraintsSqlite(): void
    {
        // lead_statuses: slug único → (tenant_id, slug) único
        DB::statement('ALTER TABLE lead_statuses RENAME TO lead_statuses_old');
        DB::statement('
            CREATE TABLE lead_statuses (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id   INTEGER NOT NULL DEFAULT 1,
                name        VARCHAR(255) NOT NULL,
                slug        VARCHAR(255) NOT NULL,
                color       VARCHAR(7)   NOT NULL DEFAULT \'#64748b\',
                bg_color    VARCHAR(7)   NOT NULL DEFAULT \'#f1f5f9\',
                description TEXT,
                "order"     INTEGER      NOT NULL DEFAULT 0,
                is_default  INTEGER      NOT NULL DEFAULT 0,
                is_active   INTEGER      NOT NULL DEFAULT 1,
                created_at  DATETIME,
                updated_at  DATETIME,
                UNIQUE (tenant_id, slug)
            )
        ');
        DB::statement('INSERT INTO lead_statuses SELECT id, tenant_id, name, slug, color, bg_color, description, "order", is_default, is_active, created_at, updated_at FROM lead_statuses_old');
        DB::statement('DROP TABLE lead_statuses_old');

        // lead_sources: name único → (tenant_id, name) único
        DB::statement('ALTER TABLE lead_sources RENAME TO lead_sources_old');
        DB::statement('
            CREATE TABLE lead_sources (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id  INTEGER NOT NULL DEFAULT 1,
                name       VARCHAR(255) NOT NULL,
                is_active  INTEGER      NOT NULL DEFAULT 1,
                "order"    INTEGER      NOT NULL DEFAULT 0,
                created_at DATETIME,
                updated_at DATETIME,
                UNIQUE (tenant_id, name)
            )
        ');
        DB::statement('INSERT INTO lead_sources SELECT id, tenant_id, name, is_active, "order", created_at, updated_at FROM lead_sources_old');
        DB::statement('DROP TABLE lead_sources_old');
    }

    /**
     * MySQL: drop el índice único simple y crea el compuesto.
     */
    private function fixUniqueConstraintsMySQL(): void
    {
        Schema::table('lead_statuses', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::table('lead_sources', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        // No revertir — los datos son aditivos y seguros de conservar
    }
};

