<?php

namespace Tests\Feature\Email;

use App\Jobs\RetryEmailAttachments;

/**
 * #6042/#6102: RetryEmailAttachments keeps per-process statics ($progress, $pushing) that survive
 * between tests in one PHP process. A class that uses this trait has them reset before and after
 * each of its tests (Laravel's setUp<Trait>/tearDown<Trait> hooks), whatever class ran before.
 * #6142: only RetryEmailAttachmentsJobTest and RetryEmailAttachmentsFailerTest use it. Other
 * classes run the job too (the email, intake, webhook and MCP email tests that import a message
 * or post to the Graph webhook) and do not reset; a class that asserts on the statics, or on a
 * path that reads them, must use it.
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
