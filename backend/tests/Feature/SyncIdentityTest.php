<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Center;
use App\Models\Room;
use App\Models\Student;
use App\Models\SyncChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_entities_receive_unique_stable_uuids_and_initial_version(): void
    {
        $center = Center::create(['name' => 'First Center']);
        $secondCenter = Center::create(['name' => 'Second Center']);
        $student = Student::create([
            'name' => 'Test Student',
            'parent_phone' => '01012345678',
            'grade_level' => 'prep_1',
            'center_id' => $center->id,
        ]);
        $secondStudent = Student::create([
            'name' => 'Second Student',
            'parent_phone' => '01012345679',
            'grade_level' => 'prep_2',
            'center_id' => $center->id,
        ]);
        $room = Room::create([
            'center_id' => $center->id,
            'name' => 'Room 1',
        ]);
        $secondRoom = Room::create([
            'center_id' => $secondCenter->id,
            'name' => 'Room 1',
        ]);
        $attendance = Attendance::create([
            'student_id' => $student->id,
            'date' => '2026-10-30',
            'status' => 'present',
        ]);
        $secondAttendance = Attendance::create([
            'student_id' => $secondStudent->id,
            'date' => '2026-10-30',
            'status' => 'absent',
        ]);

        $records = [
            $center,
            $secondCenter,
            $student,
            $secondStudent,
            $room,
            $secondRoom,
            $attendance,
            $secondAttendance,
        ];
        $uuids = array_map(fn ($record) => $record->uuid, $records);

        $this->assertCount(count($uuids), array_unique($uuids));

        foreach ($records as $record) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $record->uuid,
            );
            $this->assertSame(1, $record->version);
        }

        $originalStudentBarcode = $student->barcode;
        $this->assertNotEmpty($originalStudentBarcode);

        foreach ($records as $record) {
            $originalUuid = $record->uuid;
            $record->setAttribute('updated_at', now());
            $record->save();
            $this->assertSame($originalUuid, $record->fresh()->uuid);
            $this->assertSame(1, $record->fresh()->version);
        }

        $this->assertSame($originalStudentBarcode, $student->fresh()->barcode);
    }

    public function test_attendance_upsert_insert_sets_uuid_and_initial_version(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $center = Center::create(['name' => 'Attendance Center']);
        $student = Student::create([
            'name' => 'Attendance Student',
            'parent_phone' => '01012345678',
            'grade_level' => 'prep_1',
            'center_id' => $center->id,
        ]);

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/attendance/record', [
                'date' => '2026-10-30',
                'records' => [[
                    'student_id' => $student->id,
                    'status' => 'present',
                ]],
            ])
            ->assertOk();

        $attendance = Attendance::query()->where('student_id', $student->id)->firstOrFail();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $attendance->uuid,
        );
        $this->assertSame(1, $attendance->version);
    }

    public function test_attendance_upsert_update_preserves_uuid_and_increments_version_once(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $center = Center::create(['name' => 'Attendance Center']);
        $student = Student::create([
            'name' => 'Attendance Student',
            'parent_phone' => '01012345678',
            'grade_level' => 'prep_1',
            'center_id' => $center->id,
        ]);
        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/attendance/record', [
                'date' => '2026-10-30',
                'records' => [[
                    'student_id' => $student->id,
                    'status' => 'present',
                ]],
            ])
            ->assertOk();

        $attendance = Attendance::query()->where('student_id', $student->id)->firstOrFail();
        $originalUuid = $attendance->uuid;

        DB::table('attendances')
            ->where('id', $attendance->id)
            ->update(['version' => 7]);

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/attendance/record', [
                'date' => '2026-10-30',
                'records' => [[
                    'student_id' => $student->id,
                    'status' => 'late',
                    'absence_reason' => 'Arrived late',
                ]],
            ])
            ->assertOk();

        $attendance->refresh();

        $this->assertSame($originalUuid, $attendance->uuid);
        $this->assertSame(8, $attendance->version);
        $this->assertSame('late', $attendance->status);
        $this->assertSame('Arrived late', $attendance->absence_reason);

        $changes = SyncChange::query()->where('entity_type', 'attendance')->orderBy('id')->get();
        $this->assertSame(['created', 'updated'], $changes->pluck('operation')->all());
        $this->assertSame([1, 8], $changes->pluck('entity_version')->all());
        $this->assertSame([$originalUuid, $originalUuid], $changes->pluck('entity_uuid')->all());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertIsolatedDatabaseConfiguration();
    }

    private function assertIsolatedDatabaseConfiguration(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
    }
}
