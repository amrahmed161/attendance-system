<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@example.com');
        $existingUser = User::query()->where('email', $email)->first();

        if ($existingUser) {
            if ($existingUser->role !== 'manager') {
                throw new RuntimeException('The configured manager email belongs to a non-manager account. No changes were made.');
            }

            return;
        }

        if (User::query()->exists()) {
            throw new RuntimeException('Cannot create the configured manager while another user account exists. No changes were made.');
        }

        $name = env('ADMIN_NAME', 'System Administrator');
        $password = env('ADMIN_PASSWORD');

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Set ADMIN_EMAIL to a valid email address before seeding.');
        }

        if (! is_string($name) || trim($name) === '') {
            throw new RuntimeException('Set ADMIN_NAME to a non-empty name before seeding.');
        }

        if (! is_string($password) || strlen($password) < 8) {
            throw new RuntimeException('Set ADMIN_PASSWORD to a value of at least 8 characters before seeding.');
        }

        $manager = new User();
        $manager->name = $name;
        $manager->email = $email;
        $manager->password = $password;
        $manager->role = 'manager';
        $manager->save();
    }
}
