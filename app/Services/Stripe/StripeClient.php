<?php

namespace App\Services\Stripe;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class StripeClient
{
    private Client $http;

    public function __construct(
        private readonly array $config,
    ) {
        $this->http = new Client([
            'base_uri' => 'https://api.stripe.com',
            'timeout' => 30,
        ]);
    }

    // ── Core HTTP ──

    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, ['query' => $params]);
    }

    public function post(string $endpoint, array $data = []): array
    {
        return $this->request('POST', $endpoint, ['form_params' => $data]);
    }

    // ── Health Check ──

    public function isHealthy(): bool
    {
        try {
            $this->get('/v1/balance');
            return true;
        } catch (StripeClientException) {
            return false;
        }
    }

    // ── Customers ──

    public function listCustomers(int $limit = 100, ?string $startingAfter = null): array
    {
        $params = ['limit' => $limit];
        if ($startingAfter) {
            $params['starting_after'] = $startingAfter;
        }

        return $this->get('/v1/customers', $params);
    }

    /**
     * Fetch all customers with automatic pagination.
     */
    public function getAllCustomers(): array
    {
        $all = [];
        $startingAfter = null;

        for ($page = 0; $page < 100; $page++) {
            $response = $this->listCustomers(100, $startingAfter);
            $customers = $response['data'] ?? [];

            if (empty($customers)) {
                break;
            }

            $all = array_merge($all, $customers);

            if (! ($response['has_more'] ?? false)) {
                break;
            }

            $startingAfter = end($customers)['id'] ?? null;
            if (! $startingAfter) {
                break;
            }
        }

        return $all;
    }

    public function getCustomer(string $id): array
    {
        return $this->get("/v1/customers/{$id}");
    }

    // ── Invoices ──

    public function createInvoice(array $data): array
    {
        return $this->post('/v1/invoices', $data);
    }

    public function createInvoiceItem(array $data): array
    {
        return $this->post('/v1/invoiceitems', $data);
    }

    public function finalizeInvoice(string $id): array
    {
        return $this->post("/v1/invoices/{$id}/finalize");
    }

    public function getInvoice(string $id): array
    {
        return $this->get("/v1/invoices/{$id}");
    }

    public function sendInvoice(string $id): array
    {
        return $this->post("/v1/invoices/{$id}/send");
    }

    public function listInvoices(int $limit = 100, ?string $startingAfter = null, array $extraParams = []): array
    {
        $params = array_merge(['limit' => $limit], $extraParams);

        if ($startingAfter) {
            $params['starting_after'] = $startingAfter;
        }

        return $this->get('/v1/invoices', $params);
    }

    /**
     * Fetch all line items for an invoice (handles >10 line pagination).
     */
    public function getAllInvoiceLines(string $invoiceId): array
    {
        $all = [];
        $startingAfter = null;

        for ($page = 0; $page < 50; $page++) {
            $params = ['limit' => 100];
            if ($startingAfter) {
                $params['starting_after'] = $startingAfter;
            }

            $response = $this->get("/v1/invoices/{$invoiceId}/lines", $params);
            $lines = $response['data'] ?? [];

            if (empty($lines)) {
                break;
            }

            $all = array_merge($all, $lines);

            if (! ($response['has_more'] ?? false)) {
                break;
            }

            $startingAfter = end($lines)['id'] ?? null;
            if (! $startingAfter) {
                break;
            }
        }

        return $all;
    }

    // ── Products & Prices ──

    public function listProducts(int $limit = 100): array
    {
        return $this->get('/v1/products', ['limit' => $limit, 'active' => 'true']);
    }

    public function createProduct(array $data): array
    {
        return $this->post('/v1/products', $data);
    }

    public function updateProduct(string $id, array $data): array
    {
        return $this->post("/v1/products/{$id}", $data);
    }

    public function createPrice(array $data): array
    {
        return $this->post('/v1/prices', $data);
    }

    // ── Internal ──

    private function request(string $method, string $endpoint, array $options = []): array
    {
        $options['headers'] = [
            'Authorization' => 'Bearer ' . ($this->config['secret_key'] ?? ''),
            'Stripe-Version' => '2024-12-18.acacia',
        ];

        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            $attempts++;

            try {
                $response = $this->http->request($method, $endpoint, $options);
            } catch (GuzzleException $e) {
                $code = $e->getCode();

                // Rate limited — wait and retry
                if ($code === 429 && $attempts < $maxAttempts) {
                    $retryAfter = 1;
                    if (method_exists($e, 'getResponse') && $e->getResponse()) {
                        $retryAfter = (int) ($e->getResponse()->getHeaderLine('Retry-After') ?: 1);
                    }
                    Log::info("[StripeClient] Rate limited, retrying in {$retryAfter}s");
                    sleep($retryAfter);
                    continue;
                }

                Log::error("[StripeClient] {$method} {$endpoint} failed: {$e->getMessage()}");
                throw new StripeClientException("Stripe API error: {$e->getMessage()}", $code, $e);
            }

            $body = (string) $response->getBody();

            return json_decode($body, true) ?? [];
        }

        throw new StripeClientException('Stripe request failed after max retries');
    }
}
