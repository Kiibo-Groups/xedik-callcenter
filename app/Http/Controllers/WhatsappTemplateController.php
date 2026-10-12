<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\WhatsappTemplate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WhatsappTemplateController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->hasPermission('manage_whatsapp_templates'), 403);
        $isSuperAdmin = auth()->user()->isSuperAdmin();
        $templates    = WhatsappTemplate::with('tenant')->orderBy('display_name')->get();

        return view('whatsapp_templates.index', compact('templates', 'isSuperAdmin'));
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_whatsapp_templates'), 403);
        $tenantId = auth()->user()->tenant_id;

        $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('whatsapp_templates', 'name')->where('tenant_id', $tenantId),
            ],
            'display_name' => 'required|string|max:100',
            'language'     => 'required|string|max:10',
            'body_preview' => 'nullable|string|max:2000',
        ]);

        WhatsappTemplate::create([
            'tenant_id'    => $tenantId,
            'name'         => $request->name,
            'display_name' => $request->display_name,
            'language'     => $request->language,
            'body_preview' => $request->body_preview,
            'is_active'    => true,
        ]);

        return back()->with('success', "Template \"{$request->display_name}\" created.");
    }

    public function update(Request $request, WhatsappTemplate $whatsappTemplate)
    {
        abort_unless(auth()->user()->hasPermission('manage_whatsapp_templates'), 403);
        $tenantId = auth()->user()->tenant_id;

        $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('whatsapp_templates', 'name')
                    ->where('tenant_id', $tenantId)
                    ->ignore($whatsappTemplate->id),
            ],
            'display_name' => 'required|string|max:100',
            'language'     => 'required|string|max:10',
            'body_preview' => 'nullable|string|max:2000',
        ]);

        $whatsappTemplate->update($request->only('name', 'display_name', 'language', 'body_preview'));

        return back()->with('success', "Template \"{$whatsappTemplate->display_name}\" updated.");
    }

    public function toggleActive(WhatsappTemplate $whatsappTemplate)
    {
        abort_unless(auth()->user()->hasPermission('manage_whatsapp_templates'), 403);
        $whatsappTemplate->update(['is_active' => !$whatsappTemplate->is_active]);

        return response()->json(['success' => true, 'is_active' => $whatsappTemplate->is_active]);
    }

    public function destroy(WhatsappTemplate $whatsappTemplate)
    {
        abort_unless(auth()->user()->hasPermission('manage_whatsapp_templates'), 403);
        $name = $whatsappTemplate->display_name;
        $whatsappTemplate->delete();

        return back()->with('success', "Template \"{$name}\" deleted.");
    }
}
