<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadTagController extends Controller
{
    public function attach(Lead $lead, Tag $tag): JsonResponse
    {
        $lead->tags()->syncWithoutDetaching([$tag->id]);

        return response()->json(['ok' => true, 'tags' => $this->formatTags($lead)]);
    }

    public function detach(Lead $lead, Tag $tag): JsonResponse
    {
        $lead->tags()->detach($tag->id);

        return response()->json(['ok' => true, 'tags' => $this->formatTags($lead)]);
    }

    public function createAndAttach(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $slug = Tag::makeSlug($validated['name']);
        $base = $slug;
        $i    = 1;
        while (Tag::where('slug', $slug)->exists()) {
            $slug = $base . '_' . $i++;
        }

        $tag = Tag::firstOrCreate(
            ['slug' => $slug],
            [
                'name'       => $validated['name'],
                'color'      => '#4f46e5',
                'bg_color'   => '#e0e7ff',
                'created_by' => auth()->id(),
            ]
        );

        $lead->tags()->syncWithoutDetaching([$tag->id]);

        return response()->json(['ok' => true, 'tags' => $this->formatTags($lead)]);
    }

    private function formatTags(Lead $lead): array
    {
        $lead->load('tags');

        return $lead->tags->map(fn (Tag $t) => [
            'id'       => $t->id,
            'name'     => $t->name,
            'color'    => $t->color,
            'bg_color' => $t->bg_color,
        ])->all();
    }
}
