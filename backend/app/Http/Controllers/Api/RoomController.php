<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Services\ChangeLogService;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function __construct(private ChangeLogService $changeLog)
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'center_id' => ['nullable', 'integer', 'exists:centers,id'],
        ]);

        $rooms = Room::with('center')
            ->when($request->filled('center_id'), fn ($query) => $query->where('center_id', $request->center_id))
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rooms,
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'center_id' => ['required', 'integer', 'exists:centers,id'],
            'name' => ['required', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ]);

        $room = $this->changeLog->create('room', $validated);

        return response()->json([
            'success' => true,
            'message' => 'Room created successfully.',
            'data' => $room->load('center'),
        ], 201);
    }
}
