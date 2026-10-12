<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Services\Storage\CrmFileService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;

class AttachmentController extends Controller
{
    public function __construct(private readonly CrmFileService $fileService) {}

    public function download(int $attachment)
    {
        abort_unless(Auth::check(), 403);

        $attachment = Attachment::withoutGlobalScopes()->findOrFail($attachment);

        // Tenant ownership check — superadmins can access cross-tenant
        abort_unless(
            Auth::user()->is_superadmin || $attachment->tenant_id === Auth::user()->tenant_id,
            403
        );

        if ($attachment->isOnR2()) {
            $url = $this->fileService->generateTemporaryUrl($attachment->crm_file_id, Auth::id());
            return redirect($url);
        }

        abort_unless(Storage::disk('local')->exists($attachment->path), 404);
        return Storage::disk('local')->download($attachment->path, $attachment->filename);
    }

    public function view(int $attachment, Request $request)
    {
        abort_unless($request->hasValidSignature(), 403);

        // Bypass TenantScope — la seguridad la garantiza el signed URL
        $attachment = Attachment::withoutGlobalScopes()->findOrFail($attachment);

        // view() solo se usa para inline images — siempre en local
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        if (str_starts_with($attachment->mime_type ?? '', 'image/')) {
            return response(Storage::disk('local')->get($attachment->path))
                ->header('Content-Type', $attachment->mime_type)
                ->header('Content-Disposition', 'inline; filename="' . $attachment->filename . '"')
                ->header('Cache-Control', 'private, max-age=3600');
        }

        return Storage::disk('local')->download($attachment->path, $attachment->filename);
    }
}
