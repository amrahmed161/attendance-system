<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ManagerAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_log_in_and_receive_a_sanctum_token(): void
    {
        $manager = User::factory()->create([
            'name' => 'System Administrator',
            'email' => 'admin@example.com',
            'password' => Hash::make('manager-password'),
            'role' => 'manager',
        ]);

        $response = $this->postJson('/api/login', [
            'name' => $manager->email,
            'password' => 'manager-password',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.id', $manager->id)
            ->assertJsonPath('user.role', 'manager');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $manager->id,
            'tokenable_type' => User::class,
            'name' => 'desktop-app',
        ]);
    }

    public function test_wrong_password_does_not_issue_a_token(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('manager-password'),
            'role' => 'manager',
        ]);

        $this->postJson('/api/login', [
            'name' => 'admin@example.com',
            'password' => 'wrong-password',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_non_manager_cannot_log_in_or_upgrade_role_in_request(): void
    {
        User::factory()->create([
            'email' => 'assistant@example.com',
            'password' => Hash::make('assistant-password'),
            'role' => 'assistant',
        ]);

        $this->postJson('/api/login', [
            'name' => 'assistant@example.com',
            'password' => 'assistant-password',
            'role' => 'manager',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_non_manager_token_cannot_access_api_even_if_request_claims_manager_role(): void
    {
        $user = User::factory()->create([
            'role' => 'assistant',
        ]);
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/centers', [
                'name' => 'Unauthorized Center',
                'role' => 'manager',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('centers', 0);
    }

    public function test_user_listing_and_creation_routes_are_not_available(): void
    {
        $manager = User::factory()->create([
            'role' => 'manager',
        ]);
        $token = $manager->createToken('test-token')->plainTextToken;

        $this->withToken($token)->getJson('/api/users')->assertNotFound();

        foreach (['assistant', 'manager', 'administrator'] as $role) {
            $this->withToken($token)->postJson('/api/users', [
                'name' => 'Unexpected User',
                'email' => 'unexpected@example.com',
                'password' => 'password123',
                'role' => $role,
            ])->assertNotFound();
        }

        $this->assertDatabaseCount('users', 1);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }
}
