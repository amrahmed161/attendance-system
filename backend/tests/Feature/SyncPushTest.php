<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Center;
use App\Models\Room;
use App\Models\Student;
use App\Models\SyncChange;
use App\Models\SyncMutation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncPushTest extends TestCase
{
    use RefreshDatabase;

    private int $nextMutationId = 1000;

    public function test_create_operations_resolve_relationships_by_uuid_and_log_snapshots(): void
    {
        $centerUuid = $this->uuid(1);
        $studentUuid = $this->uuid(2);
        $roomUuid = $this->uuid(3);
        $attendanceUuid = $this->uuid(4);
        $mutations = [
            $this->mutation('center', 'create', $centerUuid, 0, ['name' => 'North Center']),
            $this->mutation('student', 'create', $studentUuid, 0, [
                'name' => 'Sample Student',
                'parent_phone' => '01012345678',
                'grade_level' => 'prep_1',
                'center_uuid' => $centerUuid,
            ]),
            $this->mutation('room', 'create', $roomUuid, 0, [
                'name' => 'Room A',
                'capacity' => 20,
                'center_uuid' => $centerUuid,
            ]),
            $this->mutation('attendance', 'create', $attendanceUuid, 0, [
                'date' => '2026-10-30',
                'status' => 'present',
                'student_uuid' => $studentUuid,
            ]),
        ];

        $response = $this->push($mutations)->assertOk();
        $response->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.1.status', 'applied')
            ->assertJsonPath('data.results.2.status', 'applied')
            ->assertJsonPath('data.results.3.status', 'applied')
            ->assertJsonPath('data.results.1.entity.barcode', '1')
            ->assertJsonPath('data.results.1.entity.center_uuid', $centerUuid)
            ->assertJsonPath('data.results.2.entity.center_uuid', $centerUuid)
            ->assertJsonPath('data.results.3.entity.student_uuid', $studentUuid);

        $student = Student::query()->where('uuid', $studentUuid)->firstOrFail();
        $room = Room::query()->where('uuid', $roomUuid)->firstOrFail();
        $attendance = Attendance::query()->where('uuid', $attendanceUuid)->firstOrFail();
        $this->assertSame(Center::query()->where('uuid', $centerUuid)->value('id'), $student->center_id);
        $this->assertSame($student->center_id, $room->center_id);
        $this->assertSame($student->id, $attendance->student_id);
        $this->assertDatabaseCount('sync_changes', 4);
    }

    public function test_update_delete_and_noop_mutations_enforce_versions_and_preserve_cascade_logs(): void
    {
        $centerUuid = $this->uuid(10);
        $studentUuid = $this->uuid(11);
        $roomUuid = $this->uuid(12);
        $attendanceUuid = $this->uuid(13);

        $this->push([
            $this->mutation('center', 'create', $centerUuid, 0, ['name' => 'North Center']),
            $this->mutation('student', 'create', $studentUuid, 0, [
                'name' => 'Sample Student',
                'parent_phone' => '01012345678',
                'grade_level' => 'prep_1',
                'center_uuid' => $centerUuid,
            ]),
            $this->mutation('room', 'create', $roomUuid, 0, [
                'name' => 'Room A',
                'center_uuid' => $centerUuid,
            ]),
            $this->mutation('attendance', 'create', $attendanceUuid, 0, [
                'date' => '2026-10-30',
                'status' => 'present',
                'student_uuid' => $studentUuid,
            ]),
        ])->assertOk();

        $noop = $this->mutation('center', 'update', $centerUuid, 1, ['name' => 'North Center']);
        $changed = $this->mutation('student', 'update', $studentUuid, 1, ['name' => 'Updated Student']);
        $this->push([$noop, $changed])
            ->assertOk()
            ->assertJsonPath('data.results.0.no_op', true)
            ->assertJsonPath('data.results.0.entity_version', 1)
            ->assertJsonPath('data.results.1.entity_version', 2);

        $this->push([
            $this->mutation('center', 'delete', $centerUuid, 1),
        ])->assertOk()->assertJsonPath('data.results.0.status', 'applied');

        $this->assertDatabaseCount('centers', 0);
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('rooms', 0);
        $this->assertDatabaseCount('attendances', 0);
        $this->assertSame(
            ['attendance', 'student', 'room', 'center'],
            SyncChange::query()->where('operation', 'deleted')->orderBy('id')->pluck('entity_type')->all(),
        );

        $studentDeletion = SyncChange::query()
            ->where('operation', 'deleted')
            ->where('entity_type', 'student')
            ->firstOrFail();
        $this->assertSame($centerUuid, $studentDeletion->payload['center_uuid']);
        $attendanceDeletion = SyncChange::query()
            ->where('operation', 'deleted')
            ->where('entity_type', 'attendance')
            ->firstOrFail();
        $this->assertSame($studentUuid, $attendanceDeletion->payload['student_uuid']);
    }

    public function test_room_student_and_attendance_updates_and_deletes_are_supported(): void
    {
        $centerUuid = $this->uuid(14);
        $studentUuid = $this->uuid(15);
        $roomUuid = $this->uuid(16);
        $attendanceUuid = $this->uuid(17);
        $this->push([
            $this->mutation('center', 'create', $centerUuid, 0, ['name' => 'North Center']),
            $this->mutation('student', 'create', $studentUuid, 0, [
                'name' => 'Sample Student',
                'parent_phone' => '01012345678',
                'grade_level' => 'prep_1',
                'center_uuid' => $centerUuid,
            ]),
            $this->mutation('room', 'create', $roomUuid, 0, [
                'name' => 'Room A',
                'center_uuid' => $centerUuid,
            ]),
            $this->mutation('attendance', 'create', $attendanceUuid, 0, [
                'date' => '2026-10-30',
                'status' => 'present',
                'student_uuid' => $studentUuid,
            ]),
        ])->assertOk();

        $updated = $this->push([
            $this->mutation('student', 'update', $studentUuid, 1, ['grade_level' => 'prep_2']),
            $this->mutation('room', 'update', $roomUuid, 1, ['capacity' => 25]),
            $this->mutation('attendance', 'update', $attendanceUuid, 1, ['status' => 'late']),
        ])->assertOk()
            ->assertJsonPath('data.results.0.entity_version', 2)
            ->assertJsonPath('data.results.1.entity_version', 2)
            ->assertJsonPath('data.results.2.entity_version', 2);

        $this->assertSame('prep_2', Student::query()->where('uuid', $studentUuid)->value('grade_level'));
        $this->assertSame(25, Room::query()->where('uuid', $roomUuid)->value('capacity'));
        $this->assertSame('late', Attendance::query()->where('uuid', $attendanceUuid)->value('status'));
        $this->assertSame(7, SyncChange::query()->count());

        $this->push([
            $this->mutation('attendance', 'delete', $attendanceUuid, 2),
            $this->mutation('room', 'delete', $roomUuid, 2),
            $this->mutation('student', 'delete', $studentUuid, 2),
        ])->assertOk()
            ->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.1.status', 'applied')
            ->assertJsonPath('data.results.2.status', 'applied');

        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('rooms', 0);
        $this->assertDatabaseCount('attendances', 0);
        $this->assertSame(3, SyncChange::query()->where('operation', 'deleted')->count());
    }

    public function test_stale_rejection_is_persisted_and_replayed_after_entity_changes(): void
    {
        $centerUuid = $this->uuid(20);
        $this->push([
            $this->mutation('center', 'create', $centerUuid, 0, ['name' => 'North Center']),
        ])->assertOk();

        $staleMutation = $this->mutation('center', 'update', $centerUuid, 0, ['name' => 'Stale Name']);
        $first = $this->push([$staleMutation])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.0.code', 'stale_version')
            ->assertJsonPath('data.results.0.current_version', 1)
            ->json('data.results.0');

        $this->push([
            $this->mutation('center', 'update', $centerUuid, 1, ['name' => 'Current Name']),
        ])->assertOk();

        $retry = $this->push([$staleMutation])->assertOk()->json('data.results.0');
        $this->assertSame($first, $retry);
        $this->assertSame('Current Name', Center::query()->where('uuid', $centerUuid)->value('name'));
        $this->assertDatabaseCount('sync_mutations', 3);
    }

    public function test_matching_idempotent_retry_replays_receipt_and_mismatched_payload_is_rejected(): void
    {
        $mutation = $this->mutation('center', 'create', $this->uuid(30), 0, ['name' => 'North Center']);
        $first = $this->push([$mutation])->assertOk()->json('data.results.0');
        $retry = $this->push([array_reverse($mutation)])->assertOk()->json('data.results.0');

        $this->assertSame($first, $retry);
        $this->assertDatabaseCount('centers', 1);
        $this->assertDatabaseCount('sync_changes', 1);
        $this->assertDatabaseCount('sync_mutations', 1);

        $differentPayload = $mutation;
        $differentPayload['data']['name'] = 'Different Name';
        $this->push([$differentPayload])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.0.code', 'mutation_uuid_reused');
        $this->assertSame('North Center', Center::query()->firstOrFail()->name);
        $this->assertDatabaseCount('sync_mutations', 1);
    }

    public function test_batch_reports_partial_failures_and_later_mutations_still_apply(): void
    {
        $centerUuid = $this->uuid(40);
        $studentUuid = $this->uuid(41);

        $response = $this->push([
            $this->mutation('center', 'create', $centerUuid, 0, ['name' => 'North Center']),
            $this->mutation('student', 'create', $studentUuid, 0, [
                'name' => 'Sample Student',
                'parent_phone' => '01012345678',
                'grade_level' => 'prep_1',
                'center_uuid' => $this->uuid(99),
            ]),
            $this->mutation('center', 'create', $this->uuid(42), 0, ['name' => 'South Center']),
        ])->assertOk();

        $response->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.1.status', 'rejected')
            ->assertJsonPath('data.results.1.code', 'relationship_not_found')
            ->assertJsonPath('data.results.2.status', 'applied');

        $this->assertDatabaseCount('centers', 2);
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('sync_mutations', 3);
    }

    public function test_unknown_and_server_controlled_fields_are_rejected_without_storing_values(): void
    {
        $password = 'secret-password-value';
        $mutation = $this->mutation('center', 'create', $this->uuid(50), 0, [
            'name' => 'North Center',
            'id' => 500,
            'uuid' => $this->uuid(51),
            'version' => 99,
            'password' => $password,
        ]);
        $response = $this->push([$mutation])->assertOk();

        $response->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.0.code', 'validation_failed');
        $this->assertStringNotContainsString($password, $response->getContent());
        $this->assertDatabaseCount('centers', 0);
        $this->assertDatabaseCount('sync_changes', 0);

        $receipt = SyncMutation::query()->firstOrFail()->receipt;
        $this->assertStringNotContainsString($password, json_encode($receipt));
    }

    public function test_invalid_base_versions_and_missing_relationships_are_rejected_deterministically(): void
    {
        $centerUuid = $this->uuid(60);
        $this->push([
            $this->mutation('center', 'create', $centerUuid, 1, ['name' => 'Invalid Create']),
            $this->mutation('room', 'create', $this->uuid(61), 0, [
                'name' => 'Room A',
                'center_uuid' => $this->uuid(62),
            ]),
        ])->assertOk()
            ->assertJsonPath('data.results.0.code', 'validation_failed')
            ->assertJsonPath('data.results.1.code', 'relationship_not_found');

        $this->assertDatabaseCount('centers', 0);
        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_sync_mutation_and_entity_change_roll_back_together_on_log_failure(): void
    {
        $shouldFail = true;
        SyncChange::creating(function (SyncChange $change) use (&$shouldFail): void {
            if ($shouldFail && $change->operation === 'created') {
                throw new RuntimeException('Simulated change-log failure.');
            }
        });

        $this->push([
            $this->mutation('center', 'create', $this->uuid(70), 0, ['name' => 'North Center']),
        ])->assertInternalServerError();
        $shouldFail = false;

        $this->assertDatabaseCount('centers', 0);
        $this->assertDatabaseCount('sync_changes', 0);
        $this->assertDatabaseCount('sync_mutations', 0);
    }

    public function test_push_rejects_unauthenticated_and_non_manager_requests(): void
    {
        $this->postJson('/api/sync/push', ['mutations' => []])->assertUnauthorized();

        $assistant = User::factory()->create(['role' => 'assistant']);
        $this->withToken($assistant->createToken('test-token')->plainTextToken)
            ->postJson('/api/sync/push', ['mutations' => []])
            ->assertForbidden();
    }

    public function test_manager_can_access_push_endpoint(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $this->withToken($manager->createToken('test-token')->plainTextToken)
            ->postJson('/api/sync/push', ['mutations' => []])
            ->assertUnprocessable();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
    }

    private function push(array $mutations)
    {
        $manager = User::factory()->create(['role' => 'manager']);

        return $this->withToken($manager->createToken('sync-push-test')->plainTextToken)
            ->postJson('/api/sync/push', ['mutations' => $mutations]);
    }

    private function mutation(
        string $entityType,
        string $operation,
        string $entityUuid,
        int $baseVersion,
        array $data = [],
    ): array {
        return [
            'mutation_uuid' => $this->uuid($this->nextMutationId++),
            'entity_type' => $entityType,
            'operation' => $operation,
            'entity_uuid' => $entityUuid,
            'base_version' => $baseVersion,
            ...($operation === 'delete' ? [] : ['data' => $data]),
        ];
    }

    private function uuid(int $number): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $number);
    }
}
