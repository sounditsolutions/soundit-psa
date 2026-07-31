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

        // psa-tmdw: stamp the ATTEMPT before sending, and unconditionally. The
        // schedule guard dedupes on this, not on delivery success — notify()'s
        // false has false negatives (a Teams timeout, or sendNew() throwing after
        // the transport already accepted the mail), and deduping on it would
        // re-send a digest that actually went out.
        TechnicianConfig::recordDigestAttempt();

        // Only stamp "digest sent" when it actually reached a channel — otherwise
        // technician_last_digest_at reports success on a non-delivery.
        if (! $notifier->notify($digest->subject, $digest->body)) {
            // psa-tmdw: exit non-zero so the scheduler can tell "nothing to send"
            // (disabled — handled above, still exit 0) from "could not send". A
            // digest that reached nobody is not a successful run.
            $this->error('Technician digest reached no delivery channel.');

            return self::FAILURE;
        }

        TechnicianConfig::recordDigestSent();

        return self::SUCCESS;
    }
}
