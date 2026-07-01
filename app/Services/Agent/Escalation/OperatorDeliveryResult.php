<?php

namespace App\Services\Agent\Escalation;

final class OperatorDeliveryResult
{
    public function __construct(
        public readonly bool $posted,
        public readonly bool $postedToChat,
        public readonly ?string $remoteMessageId,
    ) {}
}
