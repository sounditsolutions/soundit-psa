<?php

namespace App\Services\ControlD;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class ControlDClient
{
    private Client $http;

    public function __construct(
        private readonly array $config,
    ) {
        $this->http = new Client([
            // Handler injection keeps wire-level tests off the live vendor.
            'handler' => $this->config['handler'] ?? null,
            'base_uri' => 'https://api.controld.com/',
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer '.($this->config['api_key'] ?? ''),
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Make an authenticated GET request to the Control D API.
     */
    public function get(string $endpoint): array
    {
        try {
            $response = $this->http->request('GET', $endpoint);
        } catch (GuzzleException $e) {
            Log::error("[ControlDClient] GET {$endpoint} failed: {$e->getMessage()}");
            throw new ControlDClientException(
                "Control D API error: {$e->getMessage()}", $e->getCode(), $e
            );
        }

        $body = (string) $response->getBody();

        return json_decode($body, true) ?? [];
    }

    /**
     * Check if the Control D API is reachable with the configured credentials.
     */
    public function isHealthy(): bool
    {
        try {
            $this->get('profiles');

            return true;
        } catch (ControlDClientException) {
            return false;
        }
    }

    /**
     * Make an authenticated GET request scoped to a specific sub-organization.
     * Control D requires X-Force-Org-Id header to access sub-org resources.
     */
    public function getForOrg(string $endpoint, string $orgPk): array
    {
        try {
            $response = $this->http->request('GET', $endpoint, [
                'headers' => [
                    'X-Force-Org-Id' => $orgPk,
                ],
            ]);
        } catch (GuzzleException $e) {
            Log::error("[ControlDClient] GET {$endpoint} (org: {$orgPk}) failed: {$e->getMessage()}");
            throw new ControlDClientException(
                "Control D API error: {$e->getMessage()}", $e->getCode(), $e
            );
        }

        $body = (string) $response->getBody();

        return json_decode($body, true) ?? [];
    }

    /** $body may carry a deactivation PIN; keep it out of this frame's trace arguments. */
    public function postForOrg(string $endpoint, string $orgPk, #[\SensitiveParameter] array $body): array
    {
        return $this->requestForOrg('POST', $endpoint, $orgPk, $body);
    }

    public function putForOrg(string $endpoint, string $orgPk, #[\SensitiveParameter] ?array $body = null): array
    {
        return $this->requestForOrg('PUT', $endpoint, $orgPk, $body);
    }

    public function deleteForOrg(string $endpoint, string $orgPk): array
    {
        return $this->requestForOrg('DELETE', $endpoint, $orgPk);
    }

    /**
     * Strict provisioning transport: no retries, redirects, or secret-bearing errors.
     * $body may carry a deactivation PIN. #[\SensitiveParameter] redacts only the parameter
     * it decorates, never a copy held by another frame, so every frame that takes the body
     * annotates it: otherwise the rejection/transport throws below would leave the PIN in
     * live trace arguments wherever zend.exception_ignore_args is Off.
     */
    public function requestForOrg(string $method, string $endpoint, string $orgPk, #[\SensitiveParameter] ?array $body = null): array
    {
        // PUT organizations (Modify Organization) under X-Force-Org-Id modifies that
        // sub-organization; it is accepted only as a PUT with a body.
        $organizationPut = $endpoint === 'organizations' && $method === 'PUT' && $body !== null;
        if (! in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)
            || (! preg_match('/\Aprovision(?:\/[A-Za-z0-9_-]+(?:\/invalidate)?)?\z/', $endpoint)
                && ! in_array($endpoint, ['devices/types', 'profiles', 'organizations/organization'], true)
                && ! $organizationPut)
            || ! preg_match('/\A[A-Za-z0-9_-]+\z/', $orgPk)
            || ! is_string($this->config['api_key'] ?? null) || trim($this->config['api_key']) === '') {
            throw new ControlDClientException('Control D scoped request is invalid or unconfigured.');
        }
        $options = ['headers' => ['X-Force-Org-Id' => $orgPk], 'allow_redirects' => false, 'http_errors' => false];
        if ($body !== null) {
            // Vendor OpenAPI 3.0.1 "Modify Organization" (PUT /organizations): requestBody
            // application/x-www-form-urlencoded with optional parent_profile (recorded in
            // the organization-README fixture notes; no captured payload). Its live
            // behaviour under X-Force-Org-Id is unproven until the first approved step.
            // Provisioning keeps JSON.
            $options[$organizationPut ? 'form_params' : 'json'] = $body;
        }
        try {
            $response = $this->http->request($method, $endpoint, $options);
        } catch (GuzzleException) {
            // Guzzle messages/previous exceptions may contain codes, PINs or credentials.
            throw new ControlDClientException('Control D scoped request failed; outcome may be unknown.');
        }
        if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 500) {
            // Vendor error envelope: https://docs.controld.com/reference/response-conventions
            // A proxy/WAF status alone does not prove that a POST was not processed.
            $error = json_decode((string) $response->getBody());
            if ($error instanceof \stdClass && ($error->success ?? null) === false
                && ($error->error ?? null) instanceof \stdClass && is_int($error->error->code ?? null)) {
                if ($method === 'POST' || $organizationPut) {
                    throw new ControlDWriteRejectedException('Control D scoped request was explicitly rejected by the vendor envelope (HTTP 4xx).', $error->error->code, $response->getStatusCode(), $this->vendorMessage($error->error->message ?? null, $body));
                }
                throw new ControlDClientException('Control D scoped request was explicitly rejected by the vendor envelope (HTTP 4xx).');
            }
            throw new ControlDClientException('Control D scoped request failed; outcome may be unknown.');
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new ControlDClientException('Control D scoped request did not succeed.');
        }
        try {
            $decoded = json_decode((string) $response->getBody(), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ControlDClientException('Control D response is not valid JSON.');
        }
        if (! $decoded instanceof \stdClass || ($decoded->success ?? null) !== true) {
            throw new ControlDClientException('Control D response did not confirm success.');
        }

        // Keep container identity for strict response-shape checks at the consumer.
        return ['success' => true, 'body' => $decoded->body ?? null];
    }

    /**
     * Parent transport credential pre-flight. A caller can refuse definitely before
     * any request is sent, instead of mislabelling a purely local refusal as an
     * unknown vendor outcome. requestParent applies exactly this check itself.
     */
    public function isConfigured(): bool
    {
        return is_string($this->config['api_key'] ?? null) && trim($this->config['api_key']) !== '';
    }

    /**
     * Parent-only B2 transport. Exact method/path pairs, never the legacy raw-error GET.
     * Producer: docs.controld.com/reference/post_organizations-suborg (OpenAPI 3.0.1).
     * Create accepts form encoding, not an assumed JSON contract.
     */
    public function requestParent(string $method, string $endpoint, #[\SensitiveParameter] ?array $body = null): array
    {
        if (! (($method === 'POST' && $endpoint === 'organizations/suborg' && $body !== null)
            || ($method === 'GET' && $endpoint === 'organizations/sub_organizations' && $body === null))
            || ! $this->isConfigured()) {
            throw new ControlDClientException('Control D parent request is invalid or unconfigured.');
        }
        $options = ['allow_redirects' => false, 'http_errors' => false];
        if ($body !== null) {
            $options['form_params'] = $body;
        }
        try {
            $response = $this->http->request($method, $endpoint, $options);
        } catch (GuzzleException) {
            throw new ControlDClientException('Control D parent request failed; outcome may be unknown.');
        }
        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody());
        if ($method === 'POST' && $status >= 400 && $status < 500
            && $decoded instanceof \stdClass && ($decoded->success ?? null) === false
            && ($decoded->error ?? null) instanceof \stdClass && is_int($decoded->error->code ?? null)) {
            throw new ControlDWriteRejectedException('Control D parent POST was explicitly rejected by the vendor envelope.', $decoded->error->code, $status, $this->vendorMessage($decoded->error->message ?? null, $body));
        }
        if ($status < 200 || $status >= 300 || ! $decoded instanceof \stdClass
            || ($decoded->success ?? null) !== true || ! ($decoded->body ?? null) instanceof \stdClass) {
            throw new ControlDClientException('Control D parent response is unconfirmed; outcome may be unknown.');
        }

        return ['success' => true, 'body' => $decoded->body];
    }

    /** Longest vendor rejection message kept, in characters (the column holds 255). */
    public const VENDOR_MESSAGE_MAX = 200;

    /**
     * The vendor's own `error.message` from a rejection envelope, made safe to store and show
     * (C-56: vendor text only, never the raw body or a request field). Only a string is kept.
     * Control characters become spaces, whitespace is collapsed, and the result is capped at
     * VENDOR_MESSAGE_MAX characters. A vendor message can echo request input, so the WHOLE
     * message is dropped (null) when, before capping, it contains the API key, the
     * deactivation PIN, the contact email or organization name sent, or any run of 32 or
     * more letters and digits (the shape of a provisioning code or a key). Invalid UTF-8 and
     * an empty result are null too.
     */
    private function vendorMessage(mixed $message, #[\SensitiveParameter] ?array $body): ?string
    {
        if (! is_string($message) || ! mb_check_encoding($message, 'UTF-8')) {
            return null;
        }
        $clean = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', ' ', $message);
        $clean = $clean === null ? null : preg_replace('/\s+/u', ' ', $clean);
        $clean = $clean === null ? '' : trim($clean);
        if ($clean === '') {
            return null;
        }
        // Compare with every separator removed too, so a secret split by a control
        // character or whitespace is still recognised. A false match only drops the text.
        $compact = static fn (string $s): string => (string) preg_replace('/[\p{Cc}\p{Cf}\p{Z}\s]+/u', '', $s);
        if (preg_match('/[A-Za-z0-9]{32,}/', $message) || preg_match('/[A-Za-z0-9]{32,}/', $clean)) {
            return null;
        }
        $needles = [(string) ($this->config['api_key'] ?? '')];
        foreach (['contact_email', 'name', 'name_prefix', 'deactivation_pin'] as $key) {
            if (is_string($body[$key] ?? null) || is_int($body[$key] ?? null)) {
                $needles[] = (string) $body[$key];
            }
        }
        foreach ([$message, $clean, $compact($message)] as $haystack) {
            foreach ($needles as $needle) {
                $needle = trim($needle);
                if ($needle === '') {
                    continue;
                }
                if (ctype_digit($needle)
                    ? preg_match('/(?<![0-9])'.$needle.'(?![0-9])/', $haystack)
                    : (mb_stripos($haystack, $needle) !== false || mb_stripos($haystack, $compact($needle)) !== false)) {
                    return null;
                }
            }
        }

        return mb_strlen($clean) > self::VENDOR_MESSAGE_MAX ? mb_substr($clean, 0, self::VENDOR_MESSAGE_MAX - 1).'…' : $clean;
    }

    /**
     * Get all devices for a sub-organization.
     */
    public function getDevices(string $orgPk): array
    {
        $response = $this->getForOrg('devices', $orgPk);

        return $response['body']['devices'] ?? [];
    }

    /**
     * Get all sub-organizations with device counts.
     * Response is wrapped as { body: { sub_organizations: [...] } } — two-level unwrapping.
     */
    public function getSubOrganizations(): array
    {
        $response = $this->get('organizations/sub_organizations');

        return $response['body']['sub_organizations'] ?? [];
    }

    /**
     * Get the parent organization data (includes stats_endpoint).
     */
    public function getOrganization(): array
    {
        $response = $this->get('organizations/organization');

        return $response['body']['organization'] ?? [];
    }

    /**
     * Get the stats endpoint (analytics subdomain) from the org API.
     * Returns e.g. "jfk-org01" or null if not available.
     */
    public function getStatsEndpoint(): ?string
    {
        $org = $this->getOrganization();

        return $org['stats_endpoint'] ?? null;
    }
}
