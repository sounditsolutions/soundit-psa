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
        $notifier->notify($digest->subject, $digest->body);
        TechnicianConfig::recordDigestSent();

        return self::SUCCESS;
    }
}
