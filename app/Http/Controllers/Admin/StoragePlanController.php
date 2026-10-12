<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StoragePlan;
use Illuminate\Http\Request;

class StoragePlanController extends Controller
{
    public function index()
    {
        $this->authorizeSuperAdmin();

        $plans = StoragePlan::orderBy('included_bytes')->get();

        return view('storage.plans.index', compact('plans'));
    }

    public function store(Request $request)
    {
        $this->authorizeSuperAdmin();

        $data = $request->validate([
            'code'          => 'required|string|max:50|unique:storage_plans,code',
            'name'          => 'required|string|max:150',
            'included_bytes'=> 'required|integer|min:1',
            'price_monthly' => 'required|numeric|min:0',
            'currency'      => 'required|string|max:3',
            'is_active'     => 'boolean',
        ]);

        $data['is_active'] = $request->boolean('is_active');

        StoragePlan::create($data);

        return redirect()->route('admin.storage.plans.index')
            ->with('success', 'Plan created successfully.');
    }

    public function update(Request $request, StoragePlan $plan)
    {
        $this->authorizeSuperAdmin();

        $data = $request->validate([
            'name'          => 'required|string|max:150',
            'included_bytes'=> 'required|integer|min:1',
            'price_monthly' => 'required|numeric|min:0',
            'currency'      => 'required|string|max:3',
            'is_active'     => 'boolean',
        ]);

        $data['is_active'] = $request->boolean('is_active');

        $plan->update($data);

        return redirect()->route('admin.storage.plans.index')
            ->with('success', 'Plan updated successfully.');
    }

    private function authorizeSuperAdmin(): void
    {
        abort_unless(auth()->user()?->is_superadmin, 403);
    }
}
