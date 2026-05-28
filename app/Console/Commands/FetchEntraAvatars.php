<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Console\Command;

class FetchEntraAvatars extends Command
{
    protected $signature = 'avatars:fetch-entra';

    protected $description = 'Fetch profile photos from Microsoft Entra ID for all eligible users';

    public function handle(AvatarService $avatarService): int
    {
        $users = User::active()
            ->whereNotNull('microsoft_id')
            ->whereNull('avatar_path')
            ->get();

        if ($users->isEmpty()) {
            $this->info('No eligible users found.');
            return self::SUCCESS;
        }

        $this->info("Fetching Entra photos for {$users->count()} user(s)...");

        $fetched = 0;
        $skipped = 0;

        foreach ($users as $user) {
            $result = $avatarService->fetchEntraPhoto($user);

            if ($result) {
                $this->line("  <info>+</info> {$user->name}");
                $fetched++;
            } else {
                $this->line("  <comment>-</comment> {$user->name} (no photo or already cached)");
                $skipped++;
            }
        }

        $this->info("Done. Fetched: {$fetched}, Skipped: {$skipped}");

        return self::SUCCESS;
    }
}
