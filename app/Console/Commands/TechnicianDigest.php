<?php

namespace App\Console\Commands;

use App\Services\Technician\Notify\DigestBuilder;
use App\Services\Technician\Notify\OperatorNotifier;
use App\Support\TechnicianConfig;
use Illuminate\Console\Command;

class TechnicianDigest extends Command
{
    protected $signature = 'technician:digest';

    protected $description = 'Send the AI Technician daily digest to the operator (Teams + email).';

    public function handle(DigestBuilder $builder, OperatorNotifier $notifier): int
    {
        if (! TechnicianConfig::enabled() || ! TechnicianConfig::digestEnabled()) {
            return self::SUCCESS;
        }

        $digest = $builder->build();

        // psa-tmdw: only stamp "digest sent" when it actually reached a channel —
        // otherwise technician_last_digest_at reports success on a non-delivery.
        if ($notifier->notify($digest->subject, $digest->body)) {
            TechnicianConfig::recordDigestSent();
        }

        return self::SUCCESS;
    }
}
