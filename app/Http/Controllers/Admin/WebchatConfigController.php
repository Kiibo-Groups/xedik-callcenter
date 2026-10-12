<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OmniQueue;
use App\Models\WebchatConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebchatConfigController extends Controller
{
    public function index(\Illuminate\Http\Request $request)
    {
        $user         = auth()->user();
        $isSuperAdmin = $user->isSuperAdmin();

        $query = WebchatConfig::with(['omniQueue', 'tenant']);

        if ($isSuperAdmin) {
            $query->withoutGlobalScope(\App\Models\Scopes\TenantScope::class);
            if ($request->tenant_id) {
                $query->where('tenant_id', $request->tenant_id);
            }
        }

        $configs = $query->orderBy('tenant_id')->orderBy('window_title')->get();
        $tenants = $isSuperAdmin ? \App\Models\Tenant::orderBy('name')->get() : collect();

        return view('webchat_configs.index', compact('configs', 'isSuperAdmin', 'tenants'));
    }

    public function create()
    {
        $queues = OmniQueue::where('active', true)
            ->where(function ($q) {
                $q->where('type', 'chat')->orWhere('type', 'omni');
            })
            ->get();
        return view('webchat_configs.create', compact('queues'));
    }

    public function store(Request $request)
    {
        $data = $this->validateRequest($request);
        $data['interest_enabled'] = $request->boolean('interest_enabled');
        $data['privacy_enabled']  = $request->boolean('privacy_enabled');
        $data['is_active']        = $request->boolean('is_active', true);
        $data['tenant_id']        = auth()->user()->tenant_id;

        if (empty($data['business_hours'])) {
            $data['business_hours'] = [
                'mon' => ['open' => '07:00', 'close' => '17:00'],
                'tue' => ['open' => '07:00', 'close' => '17:00'],
                'wed' => ['open' => '07:00', 'close' => '17:00'],
                'thu' => ['open' => '07:00', 'close' => '17:00'],
                'fri' => ['open' => '07:00', 'close' => '17:00'],
                'sat' => null,
                'sun' => null,
            ];
        }

        WebchatConfig::create($data);
        return redirect()->route('admin.webchat-configs.index')
            ->with('success', 'Webchat configuration created.');
    }

    public function edit(WebchatConfig $webchatConfig)
    {
        $queues = OmniQueue::where('active', true)
            ->where(function ($q) {
                $q->where('type', 'chat')->orWhere('type', 'omni');
            })
            ->get();
        return view('webchat_configs.edit', [
            'config' => $webchatConfig,
            'queues' => $queues,
        ]);
    }

    public function update(Request $request, WebchatConfig $webchatConfig)
    {
        $data = $this->validateRequest($request);
        $data['interest_enabled'] = $request->boolean('interest_enabled');
        $data['privacy_enabled']  = $request->boolean('privacy_enabled');
        $data['is_active']        = $request->boolean('is_active', true);
        $webchatConfig->update($data);
        return back()->with('success', 'Configuration updated.');
    }

    public function destroy(WebchatConfig $webchatConfig)
    {
        $webchatConfig->delete();
        return redirect()->route('admin.webchat-configs.index')
            ->with('success', 'Configuration deleted.');
    }

    public function toggleActive(WebchatConfig $webchatConfig)
    {
        $webchatConfig->update(['is_active' => !$webchatConfig->is_active]);
        if (request()->expectsJson()) {
            return response()->json(['is_active' => $webchatConfig->is_active]);
        }
        return back()->with('success', 'Status updated.');
    }

    public function regenerateToken(WebchatConfig $webchatConfig)
    {
        $webchatConfig->update(['public_token' => (string) Str::uuid()]);
        return back()->with('success', 'Token regenerated. Update embed scripts.');
    }

    private function validateRequest(Request $request): array
    {
        return $request->validate([
            'omni_queue_id'      => 'required|exists:omni_queues,id',
            'allowed_origin'     => 'nullable|string|max:255',
            'button_label'       => 'nullable|string|max:100|regex:/^[^<>]*$/',
            'window_title'       => 'required|string|max:100|regex:/^[^<>]*$/',
            'window_subtitle'    => 'nullable|string|max:150|regex:/^[^<>]*$/',
            'form_subtitle'      => 'nullable|string|max:200|regex:/^[^<>]*$/',
            'primary_color'      => 'required|string|max:20',
            'icon'               => 'required|string|max:50',
            'position'           => 'required|in:br,bl',
            'label_position'     => 'required|in:top,left,right,bottom',
            'label_visibility'   => 'required|in:always,hover,never',
            'size'               => 'required|in:small,medium,large',
            'welcome_message'    => 'nullable|string|max:500|regex:/^[^<>]*$/',
            'offline_message'    => 'nullable|string|max:500|regex:/^[^<>]*$/',
            'interest_type'      => 'required|in:dropdown,text',
            'privacy_type'       => 'nullable|in:info,checkbox',
            'privacy_text'       => 'nullable|string|max:500',
            'privacy_url'        => 'nullable|url|max:255|starts_with:https://,http://',
            'interest_options'   => 'nullable|array',
            'interest_options.*' => 'string|max:100',
            'business_hours'     => 'nullable|array',
            'timezone'           => 'required|string|max:50',
        ], [
            'window_title.regex'        => 'HTML tags are not allowed in this field.',
            'window_subtitle.regex'     => 'HTML tags are not allowed in this field.',
            'button_label.regex'        => 'HTML tags are not allowed in this field.',
            'form_subtitle.regex'       => 'HTML tags are not allowed in this field.',
            'welcome_message.regex'     => 'HTML tags are not allowed in this field.',
            'offline_message.regex'     => 'HTML tags are not allowed in this field.',
            'privacy_url.starts_with'   => 'Privacy URL must start with http:// or https://',
        ]);
    }
}
