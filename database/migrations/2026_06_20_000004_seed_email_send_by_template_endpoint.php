<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $baseUrl = '{base_url}';

        $example = implode(" \\\n", [
            'curl -X POST "' . $baseUrl . '/api/emails/send-by-template?token={token}"',
            '  -H "Content-Type: application/json"',
            '  -d \'{',
            '    "template_id": 12,',
            '    "phone": "5551234567",',
            '    "leadlist_id": 3',
            '}\'',
        ]);

        $response = implode("\n", [
            '// Success',
            '{',
            '  "success": true,',
            '  "message_id": "a3f9...@smtp.host",',
            '  "conversation_id": 42,',
            '  "lead_id": null,',
            '  "to_email": "client@example.com",',
            '  "template_used": "Follow-up Email"',
            '}',
            '',
            '// Error — no email found for lead',
            '{',
            '  "success": false,',
            '  "error": "No email found for this lead.",',
            '  "lead_phone": "5551234567"',
            '}',
            '',
            '// Error — lead not found in list',
            '{',
            '  "success": false,',
            '  "error": "No lead found with phone 5551234567 in list \\"My List\\".",',
            '  "lead_phone": "5551234567"',
            '}',
        ]);

        $params = json_encode([
            ['name' => 'token',            'in' => 'query',  'required' => true,  'description' => 'Authentication token'],
            ['name' => 'template_id',      'in' => 'body',   'required' => true,  'description' => 'ID of the EmailTemplate to use'],
            ['name' => 'phone',            'in' => 'body',   'required' => true,  'description' => 'Lead phone number in the LeadList (digits only)'],
            ['name' => 'leadlist_id',      'in' => 'body',   'required' => true,  'description' => 'ID of the LeadList to search the lead in'],
            ['name' => 'email_account_id', 'in' => 'body',   'required' => false, 'description' => "Email account ID to use (optional — defaults to tenant's first active account)"],
        ]);

        DB::table('endpoint_catalog')->insert([
            'method'           => 'POST',
            'group'            => 'Emails',
            'name'             => 'Send Email by Template',
            'description'      => 'Sends an email using a predefined template to a lead in a LeadList. Resolves {{leadlist.*}} / {{lead.*}} / {{date.today}} variables automatically. The recipient email is extracted from the custom_fields of the lead.',
            'url'              => '/api/emails/send-by-template',
            'auth_description' => 'Query param: ?token={access_token}',
            'params'           => $params,
            'example'          => $example,
            'response_example' => $response,
            'sort_order'       => 10,
            'is_active'        => 1,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('endpoint_catalog')
            ->where('method', 'POST')
            ->where('url', '/api/emails/send-by-template')
            ->delete();
    }
};
