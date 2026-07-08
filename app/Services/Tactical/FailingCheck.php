<?php

namespace App\Services\Tactical;

/**
 * A single failing Tactical check, normalized for both the UI and the P5 AI
 * block (amendment A).
 *
 * IMPORTANT: `stdout` is RAW and UN-CLIPPED. Each consumer clips to its own
 * budget — P5 to its ~200-char token budget, the UI to its display width. The
 * value object must not pre-clip (a 50-char pre-clip would starve a consumer
 * that wanted 200). `stdout` is also a secret-bearing free-text member: the
 * consumer is responsible for redacting it (WikiRedactor::redact()) before it
 * reaches a prompt or a rendered panel.
 */
final readonly class FailingCheck
{
    public function __construct(
        public string $name,
        public string $status,
        public ?int $retcode,
        public string $stdout,
    ) {}
}
