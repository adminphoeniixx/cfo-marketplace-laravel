<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates a single admin login and nothing else.
 *
 * Unlike DatabaseSeeder — which also pulls in the demo catalog, vendors and
 * orders — this is safe to run against a live database. The email and password
 * can be supplied with ADMIN_EMAIL / ADMIN_PASSWORD; without ADMIN_PASSWORD a
 * random one is generated and printed once.
 */
class AdminUserSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@marketplace.test');
        $password = env('ADMIN_PASSWORD') ?: Str::password(20, symbols: false);

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => env('ADMIN_NAME', 'Store Admin'),
                'password' => Hash::make($password),
                'role' => 'admin',
                'email_verified_at' => now(),
                'is_active' => true,
            ],
        );

        if (! $user->wasRecentlyCreated) {
            $this->command?->warn("Admin [{$email}] already exists — left untouched.");

            return;
        }

        $this->command?->info("Admin created: {$email}");
        $this->command?->info("Password: {$password}");
    }
}
