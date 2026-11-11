<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\ChangeLogService;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function __construct(private ChangeLogService $changeLog)
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'center_id' => ['nullable', 'integer', 'exists:centers,id'],
            'grade_level' => ['nullable', 'in:prep_1,prep_2,prep_3'],
            'search' => ['nullable', 'string'],
        ]);

        $students = Student::with('center')
            ->when($request->filled('center_id'), fn ($query) => $query->where('center_id', $request->center_id))
            ->when($request->filled('grade_level'), fn ($query) => $query->where('grade_level', $request->grade_level))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('student_phone', 'like', "%{$search}%")
                        ->orWhere('parent_phone', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $students,
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^\S+(?:\s+\S+){1,2}$/u'],
            'student_phone' => ['nullable', 'string', 'regex:/^[0-9]{11}$/', 'unique:students,student_phone'],
            'parent_phone' => ['required', 'string', 'regex:/^[0-9]{11}$/'],
            'grade_level' => ['required', 'in:prep_1,prep_2,prep_3'],
            'center_id' => ['required', 'integer', 'exists:centers,id'],
            'barcode' => ['nullable', 'string', 'max:255', 'unique:students,barcode'],
        ]);

        $student = $this->changeLog->create('student', $validated);

        return response()->json([
            'success' => true,
            'message' => 'Student created successfully.',
            'data' => $student->load('center'),
        ], 201);
    }

    public function update(Request $request, Student $student)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', 'regex:/^\S+(?:\s+\S+){1,2}$/u'],
            'student_phone' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{11}$/', 'unique:students,student_phone,'.$student->id],
            'parent_phone' => ['sometimes', 'string', 'regex:/^[0-9]{11}$/'],
            'grade_level' => ['sometimes', 'in:prep_1,prep_2,prep_3'],
            'center_id' => ['sometimes', 'integer', 'exists:centers,id'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:255', 'unique:students,barcode,'.$student->id],
        ]);

        $this->changeLog->update($student, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Student updated successfully.',
            'data' => $student->fresh()->load('center'),
        ], 200);
    }

    public function destroy(Student $student)
    {
        $this->changeLog->delete($student);

        return response()->json([
            'success' => true,
            'message' => 'Student deleted successfully.',
        ], 200);
    }
}
