<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Center;
use App\Models\Room;
use App\Models\Student;
use App\Models\SyncChange;
use App\Services\ChangeLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ChangeLogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_creation_is_logged_after_barcode_generation_and_excludes_unapproved_attributes(): void
    {
        $service = app(ChangeLogService::class);
        $center = $service->create('center', ['name' => 'North Center']);

        $student = $service->create('student', [
            'name' => 'Sample Student',
            'parent_phone' => '01012345678',
            'grade_level' => 'prep_1',
            'center_id' => $center->getKey(),
            'password' => 'not-a-payload-value',
        ]);
        $change = SyncChange::query()->where('entity_type', 'student')->sole();

        $this->assertNotEmpty($student->barcode);
        $this->assertSame(1, $student->version);
        $this->assertSame('created', $change->operation);
        $this->assertSame($student->uuid, $change->entity_uuid);
        $this->assertSame($student->barcode, $change->payload['barcode']);
        $this->assertArrayNotHasKey('password', $change->payload);
    }

    public function test_update_logs_one_increment_and_noop_logs_nothing_without_changing_uuid(): void
    {
        $service = app(ChangeLogService::class);
        $center = $service->create('center', ['name' => 'North Center']);
        $uuid = $center->uuid;

        $service->update($center, ['name' => 'North Center']);
        $this->assertDatabaseCount('sync_changes', 1);
        $this->assertSame(1, $center->fresh()->version);

        $service->update($center, [
            'name' => 'South Center',
            'uuid' => '00000000-0000-4000-8000-000000000000',
            'version' => 99,
        ]);

        $center->refresh();
        $this->assertSame($uuid, $center->uuid);
        $this->assertSame(2, $center->version);
        $this->assertSame('South Center', $center->name);

        $changes = SyncChange::query()->orderBy('id')->get();
        $this->assertCount(2, $changes);
        $this->assertSame(['created', 'updated'], $changes->pluck('operation')->all());
        $this->assertSame(2, $changes->last()->entity_version);
        $this->assertSame($uuid, $changes->last()->entity_uuid);
    }

    public function test_attendance_upsert_logs_create_changed_update_and_noop_once_each(): void
    {
        $service = app(ChangeLogService::class);
        $center = $service->create('center', ['name' => 'North Center']);
        $student = $service->create('student', [
            'name' => 'Sample Student',
            'parent_phone' => '01012345678',
            'grade_level' => 'prep_1',
            'center_id' => $center->getKey(),
        ]);
        $attributes = [
            'student_id' => $student->getKey(),
            'date' => '2026-10-30',
            'status' => 'present',
            'absence_reason' => null,
            'recorded_by' => 'Manager',
        ];

        $attendance = $service->upsertAttendance($attributes);
        $uuid = $attendance->uuid;

        $this->assertSame(1, $attendance->version);
        $this->assertSame(1, SyncChange::query()->where('entity_type', 'attendance')->count());
        $this->assertSame('created', SyncChange::query()->where('entity_type', 'attendance')->sole()->operation);

        $attendance = $service->upsertAttendance([
            ...$attributes,
            'status' => 'late',
            'absence_reason' => 'Arrived late',
        ]);

        $this->assertSame($uuid, $attendance->uuid);
        $this->assertSame(2, $attendance->version);
        $this->assertSame(2, SyncChange::query()->where('entity_type', 'attendance')->count());
        $this->assertSame('updated', SyncChange::query()->where('entity_type', 'attendance')->latest('id')->firstOrFail()->operation);

        $service->upsertAttendance([
            ...$attributes,
            'status' => 'late',
            'absence_reason' => 'Arrived late',
        ]);

        $this->assertSame(2, Attendance::query()->sole()->version);
        $this->assertSame(2, SyncChange::query()->where('entity_type', 'attendance')->count());

        $sequenceIds = SyncChange::query()->orderBy('id')->pluck('id')->all();
        $this->assertCount(count(array_unique($sequenceIds)), $sequenceIds);
        $this->assertSame($sequenceIds, array_values(array_unique($sequenceIds)));
        $sortedSequenceIds = $sequenceIds;
        sort($sortedSequenceIds);
        $this->assertSame($sortedSequenceIds, $sequenceIds);
    }

    public function test_cascade_delete_logs_final_snapshots_for_every_descendant(): void
    {
        $service = app(ChangeLogService::class);
        $center = $service->create('center', ['name' => 'North Center']);
        $student = $service->create('student', [
            'name' => 'Sample Student',
            'parent_phone' => '01012345678',
            'grade_level' => 'prep_1',
            'center_id' => $center->getKey(),
        ]);
        $room = $service->create('room', [
            'center_id' => $center->getKey(),
            'name' => 'Room A',
            'capacity' => 20,
        ]);
        $attendance = $service->create('attendance', [
            'student_id' => $student->getKey(),
            'date' => '2026-10-30',
            'status' => 'absent',
            'absence_reason' => 'Sick',
            'recorded_by' => 'Manager',
        ]);

        $service->delete($center);

        $deletions = SyncChange::query()->where('operation', 'deleted')->orderBy('id')->get();
        $this->assertSame(
            ['attendance', 'student', 'room', 'center'],
            $deletions->pluck('entity_type')->all(),
        );
        $this->assertSame('Sick', $deletions[0]->payload['absence_reason']);
        $this->assertSame($attendance->uuid, $deletions[0]->entity_uuid);
        $this->assertSame($student->barcode, $deletions[1]->payload['barcode']);
        $this->assertSame($student->uuid, $deletions[1]->entity_uuid);
        $this->assertSame($room->uuid, $deletions[2]->entity_uuid);
        $this->assertSame('North Center', $deletions[3]->payload['name']);
        $this->assertSame($center->uuid, $deletions[3]->entity_uuid);

        $this->assertDatabaseCount('centers', 0);
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('rooms', 0);
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_failed_change_log_insert_rolls_back_entity_update(): void
    {
        $service = app(ChangeLogService::class);
        $center = $service->create('center', ['name' => 'North Center']);
        $shouldFail = true;
        SyncChange::creating(function (SyncChange $change) use (&$shouldFail): void {
            if ($shouldFail && $change->operation === 'updated') {
                throw new RuntimeException('Simulated change-log failure.');
            }
        });

        try {
            $service->update($center, ['name' => 'South Center']);
            $this->fail('The failed change-log insert should abort the update.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated change-log failure.', $exception->getMessage());
        } finally {
            $shouldFail = false;
        }

        $this->assertSame('North Center', $center->fresh()->name);
        $this->assertSame(1, $center->fresh()->version);
        $this->assertDatabaseCount('sync_changes', 1);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
    }
}
