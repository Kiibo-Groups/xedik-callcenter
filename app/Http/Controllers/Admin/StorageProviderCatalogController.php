<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StorageProviderCatalog;
use Illuminate\Http\Request;

class StorageProviderCatalogController extends Controller
{
    public function index()
    {
        $this->authorizeSuperAdmin();

        $providers = StorageProviderCatalog::orderBy('name')->get();

        return view('storage.providers.index', compact('providers'));
    }

    public function toggleActive(StorageProviderCatalog $provider)
    {
        $this->authorizeSuperAdmin();

        $provider->update(['is_active' => !$provider->is_active]);

        return response()->json(['is_active' => $provider->is_active]);
    }

    private function authorizeSuperAdmin(): void
    {
        abort_unless(auth()->user()?->is_superadmin, 403);
    }
}
