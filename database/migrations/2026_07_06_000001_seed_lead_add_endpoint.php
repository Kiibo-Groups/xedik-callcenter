<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $example = implode(" \\\n", [
            'GET "{base_url}/dialer/lead-add',
            '  ?lead_list_id=1',
            '  &token={token}',
            '  &fields=first_name,last_name,company,state"',
        ]);

        $response = implode("\n", [
            '// Tras POST exitoso redirige a:',
            'GET /dialer/lead-add?lead_list_id=1&token={token}&fields=...&added=1&lead_id=1042',
            '→ página muestra badge verde "Lead added successfully. Lead ID: #1042"',
            '',
            '// Tras POST con error redirige a:',
            'GET /dialer/lead-add?...&error=duplicate&phone=6025551234',
            '→ página muestra alerta roja, phone pre-llenado',
            '',
            '// Códigos de error posibles:',
            '  dnc        → teléfono en lista DNC',
            '  duplicate  → teléfono ya existe en la lista',
            '  invalid    → número inválido (< 7 dígitos)',
            '  archived   → lista archivada',
            '  not_found  → lista no encontrada',
        ]);

        $params = json_encode([
            ['name' => 'lead_list_id', 'in' => 'query', 'required' => true,  'description' => 'ID de la lead list donde se agregará el lead'],
            ['name' => 'token',        'in' => 'query', 'required' => true,  'description' => 'Access token activo'],
            ['name' => 'fields',       'in' => 'query', 'required' => false, 'description' => 'Campos custom a mostrar en el formulario, separados por coma en snake_case. Ej: first_name,last_name,company,state. Si se omite, solo se muestra el campo Phone.'],
            ['name' => 'phone',        'in' => 'body',  'required' => true,  'description' => 'Teléfono del lead (mínimo 7 dígitos numéricos)'],
            ['name' => 'custom_fields','in' => 'body',  'required' => false, 'description' => 'Valores de los campos custom declarados en ?fields=. Se envían como custom_fields[key]=value desde el formulario HTML.'],
        ]);

        DB::table('endpoint_catalog')->insert([
            'method'           => 'GET',
            'group'            => 'Lead Lists',
            'name'             => 'Add Lead — Screen Pop',
            'description'      => 'Abre un formulario HTML para agregar un nuevo lead a una lista, sin requerir sesión de CRM. El parámetro ?fields= define qué campos custom mostrar (cualquier número de keys en snake_case separadas por coma). El campo Phone siempre está presente. Al enviar el formulario (POST interno), valida DNC y duplicados, crea el lead y redirige de vuelta a la misma página mostrando el Lead ID asignado como confirmación.',
            'url'              => '/dialer/lead-add',
            'auth_description' => 'Query param: ?token={access_token}',
            'params'           => $params,
            'example'          => $example,
            'response_example' => $response,
            'sort_order'       => 15,
            'is_active'        => 1,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('endpoint_catalog')
            ->where('method', 'GET')
            ->where('url', '/dialer/lead-add')
            ->delete();
    }
};
