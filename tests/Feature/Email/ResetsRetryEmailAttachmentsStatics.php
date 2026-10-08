<?php

namespace Tests\Feature\Email;

use App\Jobs\RetryEmailAttachments;

/**
 * #6042/#6102: RetryEmailAttachments keeps per-process statics ($progress, $pushing) that survive
 * between tests in one PHP process. Every test class that runs the job uses this trait, so they
 * are reset before and after each test (Laravel's setUp<Trait>/tearDown<Trait> hooks), whatever
 * class ran before.
 */
trait ResetsRetryEmailAttachmentsStatics
{
    protected function setUpResetsRetryEmailAttachmentsStatics(): void
    {
        self::resetRetryEmailAttachmentsStatics();
    }

    protected function tearDownResetsRetryEmailAttachmentsStatics(): void
    {
        self::resetRetryEmailAttachmentsStatics();
    }

    protected static function resetRetryEmailAttachmentsStatics(): void
    {
        (function () {
            self::$progress = [];
            self::$pushing = [];
        })->bindTo(null, RetryEmailAttachments::class)();
    }

    /** @return array{progress: array<string, mixed>, pushing: array<string, true>} */
    protected static function retryEmailAttachmentsStatics(): array
    {
        return (fn () => ['progress' => self::$progress, 'pushing' => self::$pushing])->bindTo(null, RetryEmailAttachments::class)();
    }
}
