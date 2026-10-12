<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function available(): JsonResponse
    {
        $tags = Tag::orderBy('name')->get(['id', 'name', 'color', 'bg_color']);

        return response()->json(['tags' => $tags]);
    }

    public function index()
    {
        abort_unless(auth()->user()->hasPermission('manage_tags'), 403);

        $tags = Tag::withCount('leads')->orderBy('name')->get();
        return view('tags.index', compact('tags'));
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_tags'), 403);

        $validated = $request->validate([
            'name'     => 'required|string|max:100',
            'color'    => 'required|string|size:7',
            'bg_color' => 'required|string|size:7',
        ]);

        $slug = Tag::makeSlug($validated['name']);

        // Ensure unique slug
        $base = $slug;
        $i = 1;
        while (Tag::where('slug', $slug)->exists()) {
            $slug = $base . '_' . $i++;
        }

        Tag::create([
            'name'       => $validated['name'],
            'slug'       => $slug,
            'color'      => $validated['color'],
            'bg_color'   => $validated['bg_color'],
            'created_by' => auth()->id(),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['success' => true], 201);
        }

        return back()->with('success', "Tag \"{$validated['name']}\" created.");
    }

    public function update(Request $request, Tag $tag)
    {
        abort_unless(auth()->user()->hasPermission('manage_tags'), 403);

        $validated = $request->validate([
            'name'     => 'required|string|max:100',
            'color'    => 'required|string|size:7',
            'bg_color' => 'required|string|size:7',
        ]);

        $tag->update([
            'name'     => $validated['name'],
            'color'    => $validated['color'],
            'bg_color' => $validated['bg_color'],
        ]);

        return back()->with('success', "Tag \"{$tag->name}\" updated.");
    }

    public function destroy(Tag $tag)
    {
        abort_unless(auth()->user()->hasPermission('manage_tags'), 403);

        $leadsCount = $tag->leads()->count();

        if ($leadsCount > 0) {
            return back()->with('error', "Cannot delete \"{$tag->name}\" — it has {$leadsCount} leads assigned. Remove it from leads first.");
        }

        $tag->delete();
        return back()->with('success', "Tag deleted.");
    }
}
