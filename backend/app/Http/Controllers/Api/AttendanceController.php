<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Student;
use App\Services\ChangeLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function __construct(private ChangeLogService $changeLog)
    {
    }

    public function record(Request $request)
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_id' => ['required', 'integer', 'distinct'],
            'records.*.status' => ['required', 'in:present,absent,late'],
            'records.*.absence_reason' => ['nullable', 'string', 'max:255'],
            'records.*.recorded_by' => ['nullable', 'string', 'max:255'],
        ]);

        $studentIds = collect($validated['records'])
            ->pluck('student_id')
            ->map(fn ($studentId) => (int) $studentId)
            ->values();
        $existingStudentIds = Student::whereIn('id', $studentIds)
            ->pluck('id')
            ->map(fn ($studentId) => (int) $studentId);

        if ($existingStudentIds->count() !== $studentIds->count()) {
            throw ValidationException::withMessages([
                'records' => ['One or more selected students could not be found.'],
            ]);
        }

        DB::transaction(function () use ($validated): void {
            foreach ($validated['records'] as $item) {
                $this->changeLog->upsertAttendance([
                    'student_id' => (int) $item['student_id'],
                    'date' => $validated['date'],
                    'status' => $item['status'],
                    'absence_reason' => $item['absence_reason'] ?? null,
                    'recorded_by' => $item['recorded_by'] ?? null,
                ]);
            }
        });

        $attendanceByStudent = Attendance::with('student.center')
            ->where('date', $validated['date'])
            ->whereIn('student_id', $studentIds)
            ->get()
            ->keyBy('student_id');
        $records = $studentIds->map(fn ($studentId) => $attendanceByStudent->get($studentId))->values();

        return response()->json([
            'success' => true,
            'message' => 'Attendance recorded successfully.',
            'data' => $records,
        ], 200);
    }

    public function report(Request $request)
    {
        $validated = $request->validate([
            'student_id' => ['nullable', 'exists:students,id'],
            'center_id' => ['nullable', 'exists:centers,id'],
            'grade_level' => ['nullable', 'in:prep_1,prep_2,prep_3'],
            'date' => ['nullable', 'date'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ]);

        $attendance = Attendance::with(['student.center'])
            ->addSelect([
                'previous_status' => Attendance::query()
                    ->select('previous_attendance.status')
                    ->from('attendances as previous_attendance')
                    ->whereColumn('previous_attendance.student_id', 'attendances.student_id')
                    ->whereColumn('previous_attendance.date', '<', 'attendances.date')
                    ->orderByDesc('previous_attendance.date')
                    ->limit(1),
            ])
            ->when($request->filled('student_id'), fn ($query) => $query->where('student_id', $request->student_id))
            ->when($request->filled('center_id'), function ($query) use ($request) {
                $query->whereHas('student', function ($studentQuery) use ($request) {
                    $studentQuery->where('center_id', $request->center_id);
                });
            })
            ->when($request->filled('grade_level'), function ($query) use ($request) {
                $query->whereHas('student', function ($studentQuery) use ($request) {
                    $studentQuery->where('grade_level', $request->grade_level);
                });
            })
            ->when($request->filled('date'), fn ($query) => $query->whereDate('date', $request->date))
            ->when($request->filled('from_date'), fn ($query) => $query->whereDate('date', '>=', $request->from_date))
            ->when($request->filled('to_date'), fn ($query) => $query->whereDate('date', '<=', $request->to_date))
            ->orderBy('date', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $attendance,
        ], 200);
    }

    public function scan(Request $request)
    {
        $validated = $request->validate([
            'barcode' => ['required', 'string'],
            'date' => ['nullable', 'date'],
            'recorded_by' => ['required', 'string', 'max:255'],
        ]);

        $student = Student::where('barcode', $validated['barcode'])->first();

        if (! $student) {
            return response()->json([
                'message' => 'Student not found for this barcode.',
            ], 404);
        }

        $date = $validated['date'] ?? now()->toDateString();

        $existing = Attendance::where('student_id', $student->id)
            ->whereDate('date', $date)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Attendance already recorded for this student today.',
                'data' => $existing->load('student.center'),
            ], 409);
        }

        $attendance = $this->changeLog->create('attendance', [
            'student_id' => $student->id,
            'date' => $date,
            'status' => 'present',
            'recorded_by' => $validated['recorded_by'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Student attendance scanned successfully.',
            'data' => $attendance->load('student.center'),
        ], 200);
    }
}
