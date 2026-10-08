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
}
