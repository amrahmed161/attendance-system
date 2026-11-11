<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncChange;
use App\Services\SyncPushService;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function push(Request $request, SyncPushService $syncPush)
    {
        $validated = $request->validate([
            'mutations' => ['required', 'array', 'min:1', 'max:100'],
            'mutations.*' => ['required', 'array'],
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'results' => $syncPush->push($validated['mutations']),
            ],
        ], 200);
    }

    public function pull(Request $request)
    {
        $validated = $request->validate([
            'cursor' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        $cursor = (int) ($validated['cursor'] ?? 0);
        $limit = (int) ($validated['limit'] ?? 100);
        $changes = SyncChange::query()
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get([
                'id',
                'entity_type',
                'entity_uuid',
                'operation',
                'entity_version',
                'payload',
                'created_at',
            ]);

        $hasMore = $changes->count() > $limit;
        $page = $changes->take($limit)->values();
        $nextCursor = $page->isEmpty()
            ? $cursor
            : (int) $page->last()->id;

        return response()->json([
            'success' => true,
            'data' => [
                'changes' => $page,
                'next_cursor' => $nextCursor,
                'has_more' => $hasMore,
            ],
        ], 200);
    }
}
