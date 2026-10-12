<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessToken;
use App\Models\EndpointCatalog;
use App\Models\Scopes\TenantScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EndpointCatalogController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()->hasPermission('manage_endpoints'), 403);

        $user = auth()->user();

        $endpoints = EndpointCatalog::orderBy('sort_order')->orderBy('id')->get();

        $tokens = AccessToken::withoutGlobalScope(TenantScope::class)
            ->when(!$user->isSuperAdmin(), fn($q) => $q->where('tenant_id', $user->tenant_id))
            ->where('is_active', true)
            ->orderBy('label')
            ->get(['id', 'label', 'token']);

        return view('endpoints.index', [
            'endpoints'    => $endpoints,
            'tokens'       => $tokens,
            'isSuperAdmin' => $user->isSuperAdmin(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $data = $this->validated($request);
        EndpointCatalog::create($data);

        return back()->with('success', "Endpoint \"{$data['name']}\" created.");
    }

    public function update(Request $request, EndpointCatalog $endpointCatalog): RedirectResponse
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $data = $this->validated($request);
        $endpointCatalog->update($data);

        return back()->with('success', "Endpoint \"{$data['name']}\" updated.");
    }

    public function destroy(EndpointCatalog $endpointCatalog): RedirectResponse
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $name = $endpointCatalog->name;
        $endpointCatalog->delete();

        return back()->with('success', "Endpoint \"{$name}\" deleted.");
    }

    private function validated(Request $request): array
    {
        $request->validate([
            'method'           => 'required|string|in:GET,POST,PUT,PATCH,DELETE',
            'group'            => 'nullable|string|max:80',
            'name'             => 'required|string|max:120',
            'description'      => 'nullable|string',
            'url'              => 'required|string|max:300',
            'auth_description' => 'nullable|string|max:200',
            'params'           => 'nullable|string',
            'response_example' => 'nullable|string',
            'example'          => 'nullable|string',
            'sort_order'       => 'nullable|integer|min:0',
            'is_active'        => 'nullable|boolean',
        ]);

        // params viene como JSON string desde el campo oculto
        $paramsRaw = $request->input('params', '');
        $params    = null;
        if (!empty($paramsRaw)) {
            $decoded = json_decode($paramsRaw, true);
            $params  = is_array($decoded) ? json_encode($decoded) : null;
        }

        return [
            'method'           => $request->input('method'),
            'group'            => $request->input('group') ?: null,
            'name'             => $request->name,
            'description'      => $request->description,
            'url'              => $request->url,
            'auth_description' => $request->auth_description,
            'params'           => $params,
            'response_example' => $request->response_example,
            'example'          => $request->example,
            'sort_order'       => (int) $request->input('sort_order', 0),
            'is_active'        => $request->boolean('is_active', true),
        ];
    }
}
