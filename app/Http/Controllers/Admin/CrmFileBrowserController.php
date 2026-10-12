<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmFile;
use App\Models\Tenant;
use App\Services\Storage\CrmFileService;
use Illuminate\Http\Request;

class CrmFileBrowserController extends Controller
{
    public function __construct(private readonly CrmFileService $fileService) {}

    public function index(Request $request)
    {
        $this->authorizeSuperAdmin();

        $query = CrmFile::withoutGlobalScopes()->with(['uploader']);

        if ($tenantId = $request->integer('tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }
        if ($convId = $request->integer('conversation_id')) {
            $query->where('conversation_id', $convId);
        }
        if ($msgId = $request->integer('message_id')) {
            $query->where('message_id', $msgId);
        }
        if ($channel = $request->input('channel')) {
            $query->where('channel', $channel);
        }
        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }
        if ($source = $request->input('source')) {
            $query->where('source', $source);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status)->withTrashed();
        }

        $files   = $query->orderBy('created_at', 'desc')->paginate(50)->withQueryString();
        $tenants = Tenant::orderBy('name')->get(['id', 'name']);

        return view('storage.files.index', compact('files', 'tenants'));
    }

    public function temporaryUrl(Request $request, CrmFile $file)
    {
        $this->authorizeSuperAdmin();

        $url = $this->fileService->generateTemporaryUrl(
            $file->id,
            auth()->id(),
            ttlMinutes: 10
        );

        return response()->json(['url' => $url]);
    }

    public function destroy(CrmFile $file)
    {
        $this->authorizeSuperAdmin();

        $this->fileService->softDeleteFile($file->id, auth()->id());

        return redirect()->back()->with('success', 'File deleted (soft delete).');
    }

    private function authorizeSuperAdmin(): void
    {
        abort_unless(auth()->user()?->is_superadmin, 403);
    }
}
