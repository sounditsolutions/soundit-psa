<?php

namespace Tests\Unit\Cipp;

use App\Services\Cipp\CippClientException;
use App\Services\Cipp\CippRestWriteClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CippMailboxListingTest extends TestCase
{
    public function test_live_listing_and_unreadable_response(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        // Invoke-ListMailboxes.ps1 live Select-Object projection.
        $rows = [['UPN' => 'shared@contoso.example', 'recipientTypeDetails' => 'SharedMailbox']];
        Http::fake([
            'https://login.microsoftonline.com/synthetic-tenant/oauth2/v2.0/token' => Http::response(['access_token' => 'synthetic-token']),
            'https://cipp.example.test/api/ListMailboxes*' => Http::sequence()->push($rows)->push('unreadable'),
        ]);
        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test', 'tenant_id' => 'synthetic-tenant',
            'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-value',
        ], Cache::store(), fn () => ['93.184.216.34']);
        $this->assertSame($rows, $client->listMailboxes('contoso.example'));
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://cipp.example.test/api/ListMailboxes?tenantFilter=contoso.example');
        $this->expectException(CippClientException::class);
        $client->listMailboxes('contoso.example');
    }
}
