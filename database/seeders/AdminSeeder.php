<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');
        $name = env('ADMIN_NAME', 'Administrator');

        if (! $email || ! $password) {
            $this->command->error('AdminSeeder requires ADMIN_EMAIL and ADMIN_PASSWORD in .env');
            $this->command->line('Add these to your .env file before running the seeder:');
            $this->command->line('  ADMIN_EMAIL=you@example.com');
            $this->command->line('  ADMIN_PASSWORD=<a strong password>');
            $this->command->line('  ADMIN_NAME="Your Name"   # optional');

            return;
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name'     => $name,
                'password' => Hash::make($password),
            ],
        );

        $this->command->info("Admin user {$email} created/updated.");
    }
}
