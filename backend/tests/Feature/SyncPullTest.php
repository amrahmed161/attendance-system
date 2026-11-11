<?php

namespace Tests\Feature;

use App\Models\SyncChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncPullTest extends TestCase
{
    use RefreshDatabase;

    public function test_pull_from_zero_returns_changes_in_sequence_order_with_stored_payload(): void
    {
        $first = $this->createChange([
            'entity_type' => 'student',
            'entity_uuid' => '00000000-0000-4000-8000-000000000001',
            'entity_version' => 3,
            'payload' => [
                'id' => 12,
                'uuid' => '00000000-0000-4000-8000-000000000001',
                'version' => 3,
                'name' => 'Sample Student',
                'barcode' => '42',
            ],
        ]);
        $second = $this->createChange([
            'entity_type' => 'attendance',
            'entity_uuid' => '00000000-0000-4000-8000-000000000002',
            'entity_version' => 1,
            'payload' => [
                'id' => 13,
                'uuid' => '00000000-0000-4000-8000-000000000002',
                'version' => 1,
                'student_id' => 12,
                'status' => 'present',
            ],
        ]);

        $this->asManager()
            ->getJson('/api/sync/pull')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.changes.0.id', $first->id)
            ->assertJsonPath('data.changes.0.entity_type', 'student')
            ->assertJsonPath('data.changes.0.entity_uuid', $first->entity_uuid)
            ->assertJsonPath('data.changes.0.operation', 'created')
            ->assertJsonPath('data.changes.0.entity_version', 3)
            ->assertJsonPath('data.changes.0.payload', $first->payload)
            ->assertJsonPath('data.changes.1.id', $second->id)
            ->assertJsonPath('data.next_cursor', $second->id)
            ->assertJsonPath('data.has_more', false)
            ->assertJsonMissingPath('data.changes.0.password')
            ->assertJsonMissingPath('data.token');

        $this->assertDatabaseCount('sync_changes', 2);
    }

    public function test_pull_after_cursor_returns_only_later_changes(): void
    {
        $first = $this->createChange();
        $second = $this->createChange();
        $third = $this->createChange();

        $this->asManager()
            ->getJson('/api/sync/pull?cursor='.$first->id)
            ->assertOk()
            ->assertJsonPath('data.changes.0.id', $second->id)
            ->assertJsonPath('data.changes.1.id', $third->id)
            ->assertJsonPath('data.next_cursor', $third->id)
            ->assertJsonPath('data.has_more', false);
    }

    public function test_empty_pull_keeps_the_requested_cursor_unchanged(): void
    {
        $this->createChange();
        $cursor = (int) SyncChange::query()->max('id') + 50;

        $this->asManager()
            ->getJson('/api/sync/pull?cursor='.$cursor)
            ->assertOk()
            ->assertJsonPath('data.changes', [])
            ->assertJsonPath('data.next_cursor', $cursor)
            ->assertJsonPath('data.has_more', false);
    }

    public function test_small_limit_pages_without_skipping_or_duplicating_sequence_ids(): void
    {
        $expectedIds = [];
        for ($index = 0; $index < 5; $index++) {
            $expectedIds[] = $this->createChange()->id;
        }

        $receivedIds = [];
        $cursor = 0;
        $hasMore = true;

        while ($hasMore) {
            $response = $this->asManager()
                ->getJson('/api/sync/pull?cursor='.$cursor.'&limit=2')
                ->assertOk();
            $pageIds = array_column($response->json('data.changes'), 'id');
            $receivedIds = [...$receivedIds, ...$pageIds];
            $cursor = $response->json('data.next_cursor');
            $hasMore = $response->json('data.has_more');

            $this->assertLessThanOrEqual(2, count($pageIds));
        }

        $this->assertSame($expectedIds, $receivedIds);
        $this->assertSame(count($receivedIds), count(array_unique($receivedIds)));
    }

    public function test_has_more_is_true_only_when_another_page_exists(): void
    {
        $first = $this->createChange();
        $second = $this->createChange();
        $third = $this->createChange();

        $this->asManager()
            ->getJson('/api/sync/pull?limit=2')
            ->assertOk()
            ->assertJsonPath('data.changes.0.id', $first->id)
            ->assertJsonPath('data.changes.1.id', $second->id)
            ->assertJsonPath('data.next_cursor', $second->id)
            ->assertJsonPath('data.has_more', true);

        $this->asManager()
            ->getJson('/api/sync/pull?cursor='.$second->id.'&limit=2')
            ->assertOk()
            ->assertJsonPath('data.changes.0.id', $third->id)
            ->assertJsonPath('data.next_cursor', $third->id)
            ->assertJsonPath('data.has_more', false);
    }

    public function test_negative_and_non_integer_cursors_are_rejected(): void
    {
        $this->asManager()
            ->getJson('/api/sync/pull?cursor=-1')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cursor');

        $this->asManager()
            ->getJson('/api/sync/pull?cursor=not-an-integer')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cursor');
    }

    public function test_limit_minimum_and_maximum_are_accepted_and_out_of_range_values_rejected(): void
    {
        $this->createChange();

        $this->asManager()
            ->getJson('/api/sync/pull?limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.changes');

        $this->asManager()
            ->getJson('/api/sync/pull?limit=500')
            ->assertOk()
            ->assertJsonCount(1, 'data.changes');

        $this->asManager()
            ->getJson('/api/sync/pull?limit=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');

        $this->asManager()
            ->getJson('/api/sync/pull?limit=501')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');
    }

    public function test_pull_rejects_unauthenticated_and_non_manager_requests(): void
    {
        $this->getJson('/api/sync/pull')->assertUnauthorized();

        $assistant = User::factory()->create(['role' => 'assistant']);
        $this->withToken($assistant->createToken('test-token')->plainTextToken)
            ->getJson('/api/sync/pull')
            ->assertForbidden();
    }

    public function test_manager_can_access_pull_endpoint(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $this->withToken($manager->createToken('test-token')->plainTextToken)
            ->getJson('/api/sync/pull')
            ->assertOk();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
    }

    private function asManager(): static
    {
        $manager = User::factory()->create(['role' => 'manager']);

        return $this->withToken($manager->createToken('sync-pull-test')->plainTextToken);
    }

    private function createChange(array $overrides = []): SyncChange
    {
        $id = (int) SyncChange::query()->max('id') + 1;

        return SyncChange::query()->create(array_merge([
            'entity_type' => 'center',
            'entity_uuid' => sprintf('00000000-0000-4000-8000-%012d', $id),
            'operation' => 'created',
            'entity_version' => 1,
            'payload' => [
                'id' => $id,
                'uuid' => sprintf('00000000-0000-4000-8000-%012d', $id),
                'version' => 1,
                'name' => 'Test Center '.$id,
            ],
            'created_at' => now(),
        ], $overrides));
    }
}
