<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use Illuminate\Console\Command;
class CleanOrphanAttachments extends Command
{
    protected $signature = 'attachments:clean-orphans';
    protected $description = 'Delete unlinked attachments older than 24 hours';

    public function handle(): int
    {
        $orphans = Attachment::whereNull('attachable_type')
            ->where('created_at', '<', now()->subHours(24))
            ->get();

        $count = 0;
        foreach ($orphans as $orphan) {
            // forceDelete triggers the model's forceDeleting event which handles disk cleanup
            $orphan->forceDelete();
            $count++;
        }

        $this->info("Cleaned {$count} orphan attachments.");

        return self::SUCCESS;
    }
}
