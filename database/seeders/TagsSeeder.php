<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TagsSeeder extends Seeder
{
    private array $defaults = [
        ['name' => 'Hot Lead',   'slug' => 'hot_lead',   'color' => '#9a3412', 'bg_color' => '#fed7aa'],
        ['name' => 'VIP',        'slug' => 'vip',        'color' => '#92400e', 'bg_color' => '#fde68a'],
        ['name' => 'High Value', 'slug' => 'high_value', 'color' => '#166534', 'bg_color' => '#bbf7d0'],
        ['name' => 'Spanish',    'slug' => 'spanish',    'color' => '#1d4ed8', 'bg_color' => '#bfdbfe'],
        ['name' => 'English',    'slug' => 'english',    'color' => '#5b21b6', 'bg_color' => '#ddd6fe'],
        ['name' => 'Follow Up',  'slug' => 'follow_up',  'color' => '#374151', 'bg_color' => '#e5e7eb'],
    ];

    public function run(): void
    {
        $tenants = DB::table('tenants')->get();

        foreach ($tenants as $tenant) {
            foreach ($this->defaults as $def) {
                Tag::withoutGlobalScopes()->firstOrCreate(
                    ['tenant_id' => $tenant->id, 'slug' => $def['slug']],
                    [
                        'tenant_id' => $tenant->id,
                        'name'      => $def['name'],
                        'color'     => $def['color'],
                        'bg_color'  => $def['bg_color'],
                    ]
                );
            }
        }

        $count = count($this->defaults);
        $this->command?->info("Tags: {$count} defaults seeded per tenant (skipped if already exist).");
    }
}
