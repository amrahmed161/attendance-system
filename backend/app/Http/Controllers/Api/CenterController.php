<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Services\ChangeLogService;
use Illuminate\Http\Request;

class CenterController extends Controller
{
    public function __construct(private ChangeLogService $changeLog)
    {
    }

    public function index()
    {
        $centers = Center::withCount([
            'students as prep_1_count' => fn ($query) => $query->where('grade_level', 'prep_1'),
            'students as prep_2_count' => fn ($query) => $query->where('grade_level', 'prep_2'),
            'students as prep_3_count' => fn ($query) => $query->where('grade_level', 'prep_3'),
        ])->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $centers,
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:centers,name'],
        ]);

        $center = $this->changeLog->create('center', $validated);

        return response()->json([
            'success' => true,
            'message' => 'Center created successfully.',
            'data' => $center,
        ], 201);
    }

    public function update(Request $request, Center $center)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:centers,name,'.$center->id],
        ]);

        $this->changeLog->update($center, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Center updated successfully.',
            'data' => $center->fresh(),
        ], 200);
    }

    public function destroy(Center $center)
    {
        $this->changeLog->delete($center);

        return response()->json([
            'success' => true,
            'message' => 'Center deleted successfully.',
        ], 200);
    }
}
