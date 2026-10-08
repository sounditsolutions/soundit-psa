<?php

namespace Tests\Feature\Email;

use App\Models\Attachment;
use App\Services\AttachmentService;
use App\Services\AttachmentStoreFailedException;
use App\Services\AttachmentStorePathFailedException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * b4i (card 6ac5994e), #5706 review leads on AttachmentService's store path:
 *  - #5709: a write that returns false (the local disk is 'throw' => false) leaves no row
 *    naming a missing file; a cleanup step that throws is recorded, not swallowed.
 *  - #5716: storeUpload gets the same rollback as storeFromContent (#5553).
 *  - #5719: the Stored records carry ids, size and type; no filename or contentId.
 *  - #5805 (b5): a storage_path update that throws is thrown as an id-only exception.
 *
 * Synthetic data only.
 */
class AttachmentStoreRollbackTest extends TestCase
{
    use RefreshDatabase;

    private const FILENAME = 'Payroll-Q3-Example.xlsx';

    private const CONTENT_ID = 'img1@synthetic.example.test';

    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->logs = new TestHandler;
        $handler = $this->logs;
        foreach (array_keys(config('logging.channels')) as $name) {
            config(["logging.channels.{$name}" => [
                'driver' => 'custom',
                'via' => fn () => new \Monolog\Logger($name, [$handler]),
            ]]);
            Log::forgetChannel($name);
        }
    }

    /** The faked local disk, with the named methods returning false (a refused write). */
    private function diskRefusing(string ...$methods): void
    {
        $real = Storage::disk('local');
        $disk = \Mockery::mock(FilesystemAdapter::class, [$real->getDriver(), $real->getAdapter(), $real->getConfig()])->makePartial();
        foreach ($methods as $method) {
            $disk->shouldReceive($method)->andReturn(false);
        }
        Storage::set('local', $disk);
    }

    /** @return list<LogRecord> */
    private function withMessage(string $message): array
    {
        return array_values(array_filter($this->logs->getRecords(), fn (LogRecord $r) => $r->message === $message));
    }

    private function upload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(self::FILENAME, 'synthetic-xlsx-bytes');
    }

    /** @return array<string, array{0: \Closure(AttachmentService, self): Attachment, 1: string}> */
    public static function stores(): array
    {
        return [
            'storeFromContent' => [fn (AttachmentService $s, self $t) => $s->storeFromContent('synthetic-bytes', self::FILENAME, 'text/plain', true, self::CONTENT_ID), 'put'],
            'storeUpload' => [fn (AttachmentService $s, self $t) => $s->storeUpload($t->upload()), 'putFileAs'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stores')]
    public function test_a_write_that_returns_false_leaves_no_row_and_throws(\Closure $store, string $write): void
    {
        $this->diskRefusing($write);
        $made = null;
        Attachment::created(function (Attachment $a) use (&$made) {
            $made = $a->id;
        });

        try {
            $store(app(AttachmentService::class), $this);
            $this->fail('a refused write must not return an Attachment');
        } catch (AttachmentStoreFailedException $e) {
            $this->assertStringNotContainsString('Payroll', $e->getMessage(), 'id only, no filename');
        }

        $this->assertNotNull($made, 'positive control: the row was created before the write');
        $this->assertSame(0, Attachment::withTrashed()->count(), '#5709: no row names a file that was never written');
        $this->assertSame([], Storage::disk('local')->allFiles('attachments'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stores')]
    public function test_a_failed_storage_path_update_removes_the_row_and_the_file(\Closure $store): void
    {
        // #5716 for storeUpload; #5553 kept for storeFromContent.
        Attachment::updating(function (Attachment $a) {
            if ($a->isDirty('storage_path')) {
                throw new \RuntimeException('B4I-SYNTHETIC-UPDATE-FAIL');
            }
        });

        try {
            $store(app(AttachmentService::class), $this);
            $this->fail('the update failure is thrown');
        } catch (AttachmentStorePathFailedException $e) {
            // #5805: id only, the original kept as getPrevious().
            $this->assertSame('B4I-SYNTHETIC-UPDATE-FAIL', $e->getPrevious()?->getMessage(), 'the original is kept');
        }

        $this->assertSame(0, Attachment::withTrashed()->count(), 'no row naming the placeholder');
        $this->assertSame([], Storage::disk('local')->allFiles('attachments'), 'no orphan file');
    }

    public function test_email_intake_skips_an_attachment_whose_write_is_refused_and_stores_the_rest(): void
    {
        // The email-intake caller: a refused write skips that one attachment with a status-only
        // warning instead of throwing out of the message's import (and rolling the import back).
        $real = Storage::disk('local');
        $disk = \Mockery::mock(FilesystemAdapter::class, [$real->getDriver(), $real->getAdapter(), $real->getConfig()])->makePartial();
        $disk->shouldReceive('put')->andReturnUsing(
            fn ($path, $contents, $options = []) => str_contains($path, 'payroll') ? false : $real->put($path, $contents, $options),
        );
        Storage::set('local', $disk);
        $email = \App\Models\Email::create([
            'graph_id' => 'MSG-1', 'direction' => 'inbound', 'from_address' => 'user@example.test',
            'from_name' => 'User', 'subject' => 'Synthetic', 'body_text' => 'Synthetic body', 'received_at' => now(),
        ]);
        $graph = \Mockery::mock(\App\Services\Graph\GraphClient::class);
        $graph->shouldReceive('getMessageAttachments')->once()->andReturn([
            ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'ATT-1', 'name' => self::FILENAME,
                'contentType' => 'text/plain', 'isInline' => false, 'contentBytes' => base64_encode('synthetic-a')],
            ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'ATT-2', 'name' => 'b.txt',
                'contentType' => 'text/plain', 'isInline' => false, 'contentBytes' => base64_encode('synthetic-b')],
        ]);

        $stored = app(AttachmentService::class)->downloadEmailAttachments($email, $graph, 'support@example.test');

        $this->assertCount(1, $stored, 'the other attachment is still stored');
        $this->assertSame(1, Attachment::withTrashed()->count(), 'no row for the refused write');
        $this->assertSame([$stored[0]->storage_path], Storage::disk('local')->allFiles('attachments'));
        $skipped = $this->withMessage('[AttachmentService] Email attachment content not stored');
        $this->assertCount(1, $skipped, 'the refused write is reported, not silent');
        $this->assertSame(['ATT-1', 'store_failed'], [$skipped[0]->context['attachment_id'], $skipped[0]->context['reason']]);
        $this->assertStringNotContainsString('Payroll', json_encode($skipped[0]->context));
        // #6044 (C-56): the email id, never the Graph message id.
        $this->assertSame($email->id, $skipped[0]->context['email_id']);
        $this->assertArrayNotHasKey('graph_id', $skipped[0]->context);
        $this->assertStringNotContainsString('MSG-1', json_encode($skipped[0]->context));
    }

    public function test_a_cleanup_step_that_throws_is_recorded_by_id_step_and_class(): void
    {
        Attachment::updating(function (Attachment $a) {
            if ($a->isDirty('storage_path')) {
                throw new \RuntimeException('B4I-SYNTHETIC-UPDATE-FAIL');
            }
        });
        // The row cleanup is a query-builder delete (no model events), so it is failed at the
        // statement.
        \Illuminate\Support\Facades\DB::beforeExecuting(function (string $sql) {
            if (str_starts_with(strtolower($sql), 'delete from "attachments"')) {
                throw new \LogicException('B4I-SYNTHETIC-CLEANUP '.self::FILENAME);
            }
        });

        try {
            app(AttachmentService::class)->storeFromContent('synthetic-bytes', self::FILENAME, 'text/plain');
            $this->fail('rethrown');
        } catch (AttachmentStorePathFailedException $e) {
            $this->assertSame('B4I-SYNTHETIC-UPDATE-FAIL', $e->getPrevious()?->getMessage(), 'the original failure, not the cleanup one');
        }

        $cleanup = $this->withMessage('[Attachment] Cleanup after a failed store threw');
        $this->assertCount(1, $cleanup, '#5709: the row cleanup failure is recorded, not swallowed');
        $row = Attachment::withTrashed()->sole();
        $this->assertSame(['attachment_id' => $row->id, 'step' => 'row', 'exception' => \LogicException::class], $cleanup[0]->context);
        $this->assertStringNotContainsString('Payroll', json_encode($cleanup[0]->context));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stores')]
    public function test_a_file_cleanup_that_returns_false_is_recorded_by_id_and_step(\Closure $store): void
    {
        // #5806: the local disk is 'throw' => false, so a failed delete returns false, never throws.
        Attachment::updating(function (Attachment $a) {
            if ($a->isDirty('storage_path')) {
                throw new \RuntimeException('B4N-SYNTHETIC-UPDATE-FAIL');
            }
        });
        $this->diskRefusing('delete');
        $made = null;
        Attachment::created(function (Attachment $a) use (&$made) {
            $made = $a->id;
        });

        try {
            $store(app(AttachmentService::class), $this);
            $this->fail('rethrown');
        } catch (AttachmentStorePathFailedException $e) {
            $this->assertSame('B4N-SYNTHETIC-UPDATE-FAIL', $e->getPrevious()?->getMessage());
        }

        $this->assertNotEmpty(Storage::disk('local')->allFiles("attachments/{$made}"), 'positive control: the file was left on disk');
        $refused = $this->withMessage('[Attachment] Cleanup after a failed store returned false');
        $this->assertCount(1, $refused, '#5806: a false delete is recorded, not ignored');
        $this->assertSame(Level::Warning, $refused[0]->level);
        $this->assertSame(['attachment_id' => $made, 'step' => 'file', 'exception' => null], $refused[0]->context);
        $this->assertSame([], $this->withMessage('[Attachment] Cleanup after a failed store threw'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stores')]
    public function test_the_stored_record_carries_no_filename_or_content_id(\Closure $store): void
    {
        $attachment = $store(app(AttachmentService::class), $this);

        $this->assertStringStartsWith("attachments/{$attachment->id}/payroll-q3-example", $attachment->storage_path, 'positive control: stored');
        $records = array_values(array_filter($this->logs->getRecords(), fn (LogRecord $r) => str_starts_with($r->message, '[Attachment] Stored')));
        $this->assertCount(1, $records);
        $this->assertSame(['attachment_id', 'size', 'mime'], array_slice(array_keys($records[0]->context), 0, 3));
        $text = $records[0]->message.json_encode($records[0]->context);
        foreach (['payroll', 'Payroll', '.xlsx', self::CONTENT_ID, 'synthetic.example.test'] as $needle) {
            $this->assertStringNotContainsString($needle, $text, '#5719: no filename or contentId');
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stores')]
    public function test_a_storage_path_query_exception_reaches_no_log_with_the_filename(\Closure $store): void
    {
        // #5805 / C-56 ruling 1: a real QueryException from the storage_path UPDATE carries the
        // bound path, so the filename. What is thrown, and what the exception handler logs for
        // it, must not.
        \Illuminate\Support\Facades\DB::beforeExecuting(function (string $sql, array $bindings) {
            if (str_starts_with(strtolower($sql), 'update "attachments" set "storage_path"')) {
                throw new \Illuminate\Database\QueryException('sqlite', $sql, $bindings, new \PDOException('B5-SYNTHETIC-LOCK-WAIT'));
            }
        });

        $thrown = null;
        try {
            $store(app(AttachmentService::class), $this);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(AttachmentStorePathFailedException::class, $thrown);
        $previous = $thrown->getPrevious();
        $this->assertInstanceOf(\Illuminate\Database\QueryException::class, $previous, 'getPrevious() keeps the original');
        $this->assertStringContainsString('payroll-q3-example', $previous->getMessage(), 'positive control: the original carries the filename');
        $this->assertSame(0, Attachment::withTrashed()->count(), 'rolled back as before');

        app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->report($thrown);

        $reported = $this->withMessage($thrown->getMessage());
        $this->assertCount(1, $reported, 'positive control: the handler reported it');
        $this->assertSame(Level::Error, $reported[0]->level);
        $formatter = new \Monolog\Formatter\LineFormatter(null, null, true, true, true);
        foreach ($this->logs->getRecords() as $record) {
            $text = $formatter->format($record);
            foreach (['payroll', 'Payroll', '.xlsx', 'B5-SYNTHETIC-LOCK-WAIT'] as $needle) {
                $this->assertStringNotContainsString($needle, $text, "#5805: no filename or original message in any log record ({$record->message})");
            }
        }
        $this->assertStringNotContainsString('payroll', $thrown->getMessage());
    }

    /** The storage_path UPDATE throws a QueryException whose driver message is $message. */
    private function storagePathUpdateThrows(string $message, int|string $code = 0, int $times = PHP_INT_MAX): void
    {
        $left = $times;
        \Illuminate\Support\Facades\DB::beforeExecuting(function (string $sql, array $bindings) use ($message, $code, &$left) {
            if (str_starts_with(strtolower($sql), 'update "attachments" set "storage_path"') && $left-- > 0) {
                // The driver's code is a string ('40001'), which PDOException's constructor refuses.
                $pdo = new class($message, $code) extends \PDOException
                {
                    public function __construct(string $message, int|string $code)
                    {
                        parent::__construct($message);
                        $this->code = $code;
                    }
                };

                throw new \Illuminate\Database\QueryException('sqlite', $sql, $bindings, $pdo);
            }
        });
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stores')]
    public function test_the_failed_jobs_row_for_a_storage_path_failure_carries_no_filename_or_sql(\Closure $store): void
    {
        // #6028: the database-uuids failer prod ships stores (string) $e in failed_jobs.exception.
        // PHP's own string form appends each previous message (the SQL with the bound path) and,
        // with exception_ignore_args off, each frame's arguments (the filename passed in).
        $this->storagePathUpdateThrows('B6-SYNTHETIC-LOCK-REFUSED');
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        try {
            $thrown = null;
            try {
                $store(app(AttachmentService::class), $this);
            } catch (\Throwable $e) {
                $thrown = $e;
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
        }
        $this->assertInstanceOf(AttachmentStorePathFailedException::class, $thrown);
        $this->assertStringContainsString('payroll-q3-example', (string) $thrown->getPrevious(), 'positive control: the chain carries the filename');

        $this->assertSame('database-uuids', config('queue.failed.driver'), 'precondition: the shipped failer');
        $uuid = (string) \Illuminate\Support\Str::uuid();
        app('queue.failer')->log('database', 'default', json_encode(['uuid' => $uuid]), $thrown);
        $stored = (string) \Illuminate\Support\Facades\DB::table('failed_jobs')->where('uuid', $uuid)->value('exception');

        $this->assertStringContainsString($thrown->getMessage(), $stored, 'positive control: the row holds this exception');
        $this->assertStringContainsString(\Illuminate\Database\QueryException::class, $stored, 'the previous class is kept');
        foreach (['payroll', 'Payroll', '.xlsx', 'B6-SYNTHETIC-LOCK-REFUSED', '"storage_path"', 'update "attachments"', 'attachments/'] as $needle) {
            $this->assertStringNotContainsString($needle, $stored, "#6028: no filename, path or SQL in failed_jobs.exception ({$needle})");
        }
    }

    /** @return array<string, array{0: string, 1: int|string}> */
    public static function passedThrough(): array
    {
        return [
            'deadlock' => ['Deadlock found when trying to get lock; try restarting transaction', 0],
            'lock wait' => ['Lock wait timeout exceeded; try restarting transaction', 0],
            'serialization (40001)' => ['B6-SYNTHETIC-SERIALIZATION', '40001'],
            'lost connection' => ['MySQL server has gone away', 0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('passedThrough')]
    public function test_a_concurrency_or_lost_connection_throw_is_rethrown_as_a_query_exception_without_the_path(string $message, int|string $code): void
    {
        // #6029: the rethrow is a QueryException on the driver's exception with the bindings
        // withheld: the class, the code and the driver text the framework's detectors read are
        // kept, the bound path is not; the row and file are still rolled back. Exception argument
        // capture is off, as in prod (ruling 5).
        $this->storagePathUpdateThrows($message, $code);
        $ignoreArgs = ini_set('zend.exception_ignore_args', '1');
        try {
            $thrown = null;
            try {
                app(AttachmentService::class)->storeFromContent('synthetic-bytes', self::FILENAME, 'text/plain');
            } catch (\Throwable $e) {
                $thrown = $e;
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
        }

        $this->assertInstanceOf(\Illuminate\Database\QueryException::class, $thrown, 'a caller catching QueryException still matches');
        $this->assertSame(0, Attachment::withTrashed()->count(), 'rolled back as before');
        $this->assertSame([], Storage::disk('local')->allFiles('attachments'));
        $this->assertSame($message, $thrown->getPrevious()?->getMessage(), 'the driver exception is kept as previous');
        $this->assertSame($code, $thrown->getCode(), 'the driver code is kept');
        $this->assertStringStartsWith($message, $thrown->getMessage(), 'the driver text is kept');
        $this->assertStringContainsString('set "storage_path" = ?', $thrown->getMessage(), 'positive control: the SQL is still in the message');
        $this->assertSame([], $thrown->getBindings(), 'the bound path is withheld');
        $this->assertTrue(
            (new \Illuminate\Database\ConcurrencyErrorDetector)->causedByConcurrencyError($thrown)
                || (new \Illuminate\Database\LostConnectionDetector)->causedByLostConnection($thrown),
            'the framework still classifies the rethrow',
        );

        // The sinks: the exception handler's log record and the shipped failer's failed_jobs row.
        app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->report($thrown);
        $this->assertCount(1, $this->withMessage($thrown->getMessage()), 'positive control: the handler reported it');
        $this->assertSame('database-uuids', config('queue.failed.driver'), 'precondition: the shipped failer');
        $uuid = (string) \Illuminate\Support\Str::uuid();
        app('queue.failer')->log('database', 'default', json_encode(['uuid' => $uuid]), $thrown);
        $stored = (string) \Illuminate\Support\Facades\DB::table('failed_jobs')->where('uuid', $uuid)->value('exception');
        $this->assertStringContainsString($message, $stored, 'positive control: the row holds this exception');
        $formatter = new \Monolog\Formatter\LineFormatter(null, null, true, true, true);
        $sinks = array_map(fn ($record) => $formatter->format($record), $this->logs->getRecords());
        $sinks[] = $stored;
        foreach ($sinks as $text) {
            foreach (['payroll', 'Payroll', '.xlsx', 'attachments/'] as $needle) {
                $this->assertStringNotContainsString($needle, $text, "#6029: no filename or path in a log record or failed_jobs.exception ({$needle})");
            }
        }
    }

    public function test_a_deadlock_inside_a_transaction_reaches_the_frameworks_deadlock_handling(): void
    {
        // #6029: inside a nested DB::transaction (RefreshDatabase holds the outer one) the
        // framework turns a deadlock into DeadlockException for the outermost level to retry;
        // a wrapped throw was rethrown as itself.
        $this->storagePathUpdateThrows('Deadlock found when trying to get lock; try restarting transaction', 0, 1);

        $thrown = null;
        try {
            \Illuminate\Support\Facades\DB::transaction(fn () => app(AttachmentService::class)->storeFromContent('synthetic-bytes', self::FILENAME, 'text/plain'), 3);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\Illuminate\Database\DeadlockException::class, $thrown);
        $this->assertInstanceOf(\Illuminate\Database\QueryException::class, $thrown->getPrevious());
        $this->assertStringContainsString('Deadlock found', $thrown->getMessage(), 'positive control: DeadlockException copies the rethrow message');
        foreach (['payroll', 'Payroll', '.xlsx', 'attachments/'] as $needle) {
            $this->assertStringNotContainsString($needle, $thrown->getMessage(), "#6029: the DeadlockException carries no filename or path ({$needle})");
        }
    }

    public function test_another_query_exception_is_still_wrapped(): void
    {
        // #6029 control: only the detectors' errors pass through; any other is id-only (#5805).
        $this->storagePathUpdateThrows('B6-SYNTHETIC-CONSTRAINT');

        $this->expectException(AttachmentStorePathFailedException::class);
        app(AttachmentService::class)->storeFromContent('synthetic-bytes', self::FILENAME, 'text/plain');
    }

    /** @return array<string, array{0: string}> */
    public static function needleFilenames(): array
    {
        // The extension survives sanitizeFilename lowercased, spaces kept.
        return [
            'lost-connection needle' => ['Payroll-Q3-Example.server has gone away'],
            'concurrency needle' => ['Payroll-Q3-Example.database is locked'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('needleFilenames')]
    public function test_a_filename_carrying_a_detector_needle_is_still_wrapped(string $filename): void
    {
        // #6029: the detectors read the driver exception, whose message has no bindings, never
        // the QueryException, whose message carries the bound path and so the sender's filename.
        $this->storagePathUpdateThrows('B6-SYNTHETIC-DATA-TOO-LONG');

        $thrown = null;
        try {
            app(AttachmentService::class)->storeFromContent('synthetic-bytes', $filename, 'text/plain');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(AttachmentStorePathFailedException::class, $thrown, 'wrapped, id only (#5805)');
        $original = $thrown->getPrevious();
        $this->assertInstanceOf(\Illuminate\Database\QueryException::class, $original);
        $this->assertTrue(
            (new \Illuminate\Database\ConcurrencyErrorDetector)->causedByConcurrencyError($original)
                || (new \Illuminate\Database\LostConnectionDetector)->causedByLostConnection($original),
            'positive control: read on the QueryException, a detector matches the filename in the bound path',
        );
        $this->assertStringNotContainsString('payroll', $thrown->getMessage());
        $this->assertSame(0, Attachment::withTrashed()->count(), 'rolled back as before');
    }
}
