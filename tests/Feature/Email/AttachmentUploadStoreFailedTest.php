<?php

namespace Tests\Feature\Email;

use App\Enums\ClientStage;
use App\Enums\PersonType;
use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * b4m (card 6ac5994e), #5809: a refused disk write on an upload (AttachmentStoreFailedException,
 * #5709/#5716) answers 503 with a status-only body on both upload surfaces, the staff
 * AttachmentController::store and the portal uploadAttachment, and is recorded status-only.
 *
 * Synthetic data only (example.test).
 */
class AttachmentUploadStoreFailedTest extends TestCase
{
    use RefreshDatabase;

    private const FILENAME = 'Payroll-Q3-Example.png';

    private const RECORD = '[Attachment] Upload not stored: the disk refused the write';

    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
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

    /** The faked local disk with putFileAs returning false: the disk refuses the write. */
    private function diskRefusing(): void
    {
        $real = Storage::disk('local');
        $disk = \Mockery::mock(FilesystemAdapter::class, [$real->getDriver(), $real->getAdapter(), $real->getConfig()])->makePartial();
        $disk->shouldReceive('putFileAs')->andReturn(false);
        Storage::set('local', $disk);
    }

    private function file(): UploadedFile
    {
        return UploadedFile::fake()->image(self::FILENAME);
    }

    /** @return array<string, array{0: string}> */
    public static function surfaces(): array
    {
        return ['staff AttachmentController::store' => ['staff'], 'portal uploadAttachment' => ['portal']];
    }

    /** Posts one upload on $surface to a ticket that surface may write to. */
    private function upload(string $surface): array
    {
        $client = Client::factory()->create(['stage' => ClientStage::Active]);
        if ($surface === 'staff') {
            $ticket = Ticket::factory()->create(['client_id' => $client->id, 'status' => TicketStatus::New]);

            return [$ticket, $this->actingAs(User::factory()->create())
                ->post(route('tickets.attachments.store', $ticket), ['file' => $this->file()], ['Accept' => 'application/json'])];
        }
        Setting::setValue('portal_enabled', '1');
        $person = Person::create(['client_id' => $client->id, 'person_type' => PersonType::User,
            'first_name' => 'Portal', 'last_name' => 'User', 'email' => 'portal-b4m@example.test',
            'is_active' => true, 'portal_enabled' => true, 'company_wide_access' => true]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id, 'status' => TicketStatus::New]);

        return [$ticket, $this->actingAs($person, 'portal')
            ->post(route('portal.tickets.attachments.store', $ticket), ['file' => $this->file()], ['Accept' => 'application/json'])];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function test_a_stored_upload_still_answers_200(string $surface): void
    {
        // Positive control: the route, the auth and the store all work on a disk that accepts.
        [, $response] = $this->upload($surface);

        $response->assertOk();
        $this->assertSame(1, Attachment::count());
        $this->assertSame([], $this->records());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function test_a_refused_disk_write_answers_503_status_only_and_is_recorded(string $surface): void
    {
        $this->diskRefusing();

        [$ticket, $response] = $this->upload($surface);

        $response->assertStatus(503);
        $this->assertSame(['message' => 'The file could not be stored. Try again.'], $response->json(), 'C-56: status-only body');
        $this->assertStringNotContainsString('Payroll', $response->getContent());
        $this->assertSame(0, Attachment::withTrashed()->count(), 'the store removed its own row (#5716)');
        $records = $this->records();
        $this->assertCount(1, $records, 'never silent: one record');
        $this->assertSame(Level::Warning, $records[0]->level);
        $this->assertSame([
            'ticket_id' => $ticket->id,
            'surface' => $surface,
            'exception' => \App\Services\AttachmentStoreFailedException::class,
        ], $records[0]->context, 'C-56: ids, surface and class only');
    }

    /** @return list<LogRecord> every record at any level whose message is the upload record */
    private function records(): array
    {
        return array_values(array_filter($this->logs->getRecords(), fn (LogRecord $r) => $r->message === self::RECORD));
    }
}
