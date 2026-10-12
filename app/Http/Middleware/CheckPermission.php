<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (!auth()->check()) {
            return redirect()->route('login');
        }

        if (!auth()->user()->hasPermission($permission)) {
            Log::warning("Unauthorized access attempt", [
                'user_id' => auth()->id(),
                'permission' => $permission,
                'path' => $request->path(),
            ]);
            abort(403, 'You do not have permission to access this resource.');
        }

        return $next($request);
    }
}