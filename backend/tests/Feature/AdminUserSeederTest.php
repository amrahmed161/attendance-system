<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_leaves_existing_manager_and_auth_tokens_unchanged(): void
    {
        $passwordHash = Hash::make('original-manager-password');
        $manager = User::factory()->create([
            'name' => 'System Administrator',
            'email' => 'admin@example.com',
            'password' => $passwordHash,
            'role' => 'manager',
        ]);
        $manager->createToken('existing-token');

        $this->withEnvironment([
            'ADMIN_EMAIL' => 'admin@example.com',
            'ADMIN_NAME' => 'Changed Name',
            'ADMIN_PASSWORD' => 'changed-manager-password',
        ], fn () => app(AdminUserSeeder::class)->run());

        $manager->refresh();

        $this->assertSame(1, $manager->id);
        $this->assertSame('System Administrator', $manager->name);
        $this->assertSame('admin@example.com', $manager->email);
        $this->assertSame($passwordHash, $manager->password);
        $this->assertSame('manager', $manager->role);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_seeder_creates_manager_from_environment_when_no_users_exist(): void
    {
        $this->withEnvironment([
            'ADMIN_EMAIL' => 'admin@example.com',
            'ADMIN_NAME' => 'System Administrator',
            'ADMIN_PASSWORD' => 'manager-password',
        ], fn () => app(AdminUserSeeder::class)->run());

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', [
            'id' => 1,
            'name' => 'System Administrator',
            'email' => 'admin@example.com',
            'role' => 'manager',
        ]);
        $this->assertTrue(Hash::check(
            'manager-password',
            User::query()->where('email', 'admin@example.com')->value('password'),
        ));
    }

    public function test_seeder_refuses_to_create_a_second_account(): void
    {
        User::factory()->create([
            'email' => 'existing@example.com',
            'role' => 'manager',
        ]);

        $this->withEnvironment([
            'ADMIN_EMAIL' => 'admin@example.com',
            'ADMIN_NAME' => 'System Administrator',
            'ADMIN_PASSWORD' => 'manager-password',
        ], function (): void {
            try {
                app(AdminUserSeeder::class)->run();
                $this->fail('Expected the seeder to refuse creating a second account.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('another user account exists', $exception->getMessage());
            }
        });

        $this->assertDatabaseCount('users', 1);
    }

    private function withEnvironment(array $values, callable $callback): mixed
    {
        $previous = [];

        foreach ($values as $key => $value) {
            $previous[$key] = [
                'getenv' => getenv($key),
                'env' => array_key_exists($key, $_ENV) ? $_ENV[$key] : null,
                'has_env' => array_key_exists($key, $_ENV),
                'server' => array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null,
                'has_server' => array_key_exists($key, $_SERVER),
            ];
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        try {
            return $callback();
        } finally {
            foreach ($previous as $key => $value) {
                if ($value['getenv'] === false) {
                    putenv($key);
                } else {
                    putenv($key.'='.$value['getenv']);
                }

                if ($value['has_env']) {
                    $_ENV[$key] = $value['env'];
                } else {
                    unset($_ENV[$key]);
                }

                if ($value['has_server']) {
                    $_SERVER[$key] = $value['server'];
                } else {
                    unset($_SERVER[$key]);
                }
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }
}
