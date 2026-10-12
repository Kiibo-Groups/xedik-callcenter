<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoint_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('method', 10);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('url', 300);
            $table->string('auth_description', 200)->nullable();
            $table->text('params')->nullable();          // JSON array
            $table->text('response_example')->nullable();
            $table->text('example')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $base = config('app.url', 'https://your-domain.com');

        $endpoints = [
            [
                'method'           => 'GET',
                'name'             => 'Lead View / Screen Pop',
                'description'      => 'Opens a lead\'s detail page from an external system without requiring a CRM login. Pass view=html (default) to get an editable form, or view=json to receive the lead data as JSON — useful for dialers that need to pre-populate their own UI.',
                'url'              => $base . '/dialer/lead-view',
                'auth_description' => 'Query param: token={access_token}',
                'params'           => json_encode([
                    ['name' => 'phone',        'in' => 'query', 'required' => true,  'description' => '10-digit phone number of the lead to look up'],
                    ['name' => 'lead_list_id', 'in' => 'query', 'required' => true,  'description' => 'ID of the lead list to search in'],
                    ['name' => 'token',        'in' => 'query', 'required' => true,  'description' => 'Active access token'],
                    ['name' => 'view',         'in' => 'query', 'required' => false, 'description' => 'html (default) — editable form page | json — returns lead data as JSON'],
                ]),
                'response_example' => '// view=json response:' . "\n" . '{"ok":true,"phone":"6025551234","first_name":null,"last_name":null,"status":"NEW","custom_fields":{"company":"Acme Corp","state":"AZ"}}' . "\n\n" . '// Lead not found:' . "\n" . '{"ok":false,"error":"Lead not found."}',
                'example'          => 'GET ' . $base . '/dialer/lead-view?phone=6025551234&lead_list_id=1&token={token}&view=json',
                'sort_order'       => 10,
                'is_active'        => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'method'           => 'POST',
                'name'             => 'Add Lead to List',
                'description'      => 'Adds a new lead to a specific lead list. Automatically skips the record if the phone number is on the DNC list or already exists in the list, returning a descriptive skipped reason.',
                'url'              => $base . '/api/external/lead-lists/{lead_list_id}/leads',
                'auth_description' => 'Query param: token={access_token}',
                'params'           => json_encode([
                    ['name' => 'token',         'in' => 'query', 'required' => true,  'description' => 'Active access token'],
                    ['name' => 'phone',         'in' => 'body',  'required' => true,  'description' => 'Phone number (digits only, minimum 7 digits)'],
                    ['name' => 'custom_fields', 'in' => 'body',  'required' => false, 'description' => 'JSON object with additional contact fields, e.g. {"company":"Acme","city":"Phoenix"}'],
                ]),
                'response_example' => '{"ok":true,"lead_id":1042,"phone":"6025551234"}' . "\n\n" . '// Skipped — DNC:' . "\n" . '{"ok":false,"error":"Phone is on DNC list.","skipped":"dnc"}' . "\n\n" . '// Skipped — duplicate:' . "\n" . '{"ok":false,"error":"Phone already exists in this list.","skipped":"duplicate"}',
                'example'          => 'POST ' . $base . '/api/external/lead-lists/1/leads?token={token}' . "\n" . 'Content-Type: application/json' . "\n\n" . '{"phone":"6025551234","custom_fields":{"company":"Acme Corp","state":"AZ"}}',
                'sort_order'       => 20,
                'is_active'        => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'method'           => 'PATCH',
                'name'             => 'Update Lead',
                'description'      => 'Updates the custom fields of an existing lead identified by phone number. Performs a merge — only the keys you send are updated; other keys in the JSON are preserved. HTML tags are stripped from all values.',
                'url'              => $base . '/api/external/lead-lists/{lead_list_id}/leads/{phone}',
                'auth_description' => 'Query param: token={access_token}',
                'params'           => json_encode([
                    ['name' => 'token',         'in' => 'query', 'required' => true,  'description' => 'Active access token'],
                    ['name' => 'lead_list_id',  'in' => 'path',  'required' => true,  'description' => 'ID of the lead list'],
                    ['name' => 'phone',         'in' => 'path',  'required' => true,  'description' => 'Phone number to identify the lead'],
                    ['name' => 'custom_fields', 'in' => 'body',  'required' => true,  'description' => 'JSON object with fields to update (merged with existing)'],
                ]),
                'response_example' => '{"ok":true,"phone":"6025551234"}' . "\n\n" . '// Not found:' . "\n" . '{"ok":false,"error":"Lead not found."}',
                'example'          => 'PATCH ' . $base . '/api/external/lead-lists/1/leads/6025551234?token={token}' . "\n" . 'Content-Type: application/json' . "\n\n" . '{"custom_fields":{"disposition":"callback","notes":"Interested, call back Monday"}}',
                'sort_order'       => 30,
                'is_active'        => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'method'           => 'DELETE',
                'name'             => 'Delete Lead',
                'description'      => 'Permanently removes a lead from a list by phone number. This action cannot be undone. The lead count on the list is decremented automatically.',
                'url'              => $base . '/api/external/lead-lists/{lead_list_id}/leads/{phone}',
                'auth_description' => 'Query param: token={access_token}',
                'params'           => json_encode([
                    ['name' => 'token',        'in' => 'query', 'required' => true, 'description' => 'Active access token'],
                    ['name' => 'lead_list_id', 'in' => 'path',  'required' => true, 'description' => 'ID of the lead list'],
                    ['name' => 'phone',        'in' => 'path',  'required' => true, 'description' => 'Phone number to identify the lead'],
                ]),
                'response_example' => '{"ok":true,"phone":"6025551234"}' . "\n\n" . '// Not found:' . "\n" . '{"ok":false,"error":"Lead not found."}',
                'example'          => 'DELETE ' . $base . '/api/external/lead-lists/1/leads/6025551234?token={token}',
                'sort_order'       => 40,
                'is_active'        => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
        ];

        DB::table('endpoint_catalog')->insert($endpoints);
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_catalog');
    }
};
