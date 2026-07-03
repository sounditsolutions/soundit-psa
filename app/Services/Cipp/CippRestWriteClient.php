<?php

namespace App\Services\Cipp;

use App\Support\SafeUrlInspector;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CippRestWriteClient
{
    private const TOKEN_CACHE_KEY = 'cipp_rest_write_oauth_token';

    /** @var callable */
    private $resolver;

    public function __construct(
        private readonly array $config,
        private readonly CacheInterface $cache,
        ?callable $resolver = null,
    ) {
        $this->resolver = $resolver ?? 'gethostbynamel';
    }

    /** @return array<int|string, mixed> */
    public function setUserSignInState(string $tenantFilter, string $userId, bool $enabled): array
    {
        return $this->send('api/ExecDisableUser', [
            'tenantFilter' => $tenantFilter,
            'ID' => $userId,
            'Enable' => $enabled,
        ]);
    }

    /** @return array<int|string, mixed> */
    public function revokeUserSessions(string $tenantFilter, string $userId, string $userPrincipalName): array
    {
        return $this->send('api/ExecRevokeSessions', [
            'tenantFilter' => $tenantFilter,
            'id' => $userId,
            'Username' => $userPrincipalName,
        ]);
    }

    /** @return array<int|string, mixed> */
    public function removeUserMfaMethods(string $tenantFilter, string $userPrincipalName): array
    {
        return $this->send('api/ExecResetMFA', [
            'tenantFilter' => $tenantFilter,
            'ID' => $userPrincipalName,
        ]);
    }

    /** @return array<int|string, mixed> */
    public function setLegacyPerUserMfa(string $tenantFilter, string $userPrincipalName, string $userId, string $state): array
    {
        return $this->send('api/ExecPerUserMFA', [
            'tenantFilter' => $tenantFilter,
            'userPrincipalName' => $userPrincipalName,
            'userId' => $userId,
            'State' => $state,
        ]);
    }

    /** @return array<int|string, mixed> */
    public function assignUserLicense(string $tenantFilter, string $userId, string $skuId): array
    {
        return $this->send('api/ExecBulkLicense', [[
            'tenantFilter' => $tenantFilter,
            'userIds' => [$userId],
            'LicenseOperation' => 'Add',
            'Licenses' => [['value' => $skuId]],
            'LicensesToRemove' => [],
            'RemoveAllLicenses' => false,
            'ReplaceAllLicenses' => false,
        ]]);
    }

    /** @return array<int|string, mixed> */
    public function removeUserLicense(string $tenantFilter, string $userId, string $skuId): array
    {
        return $this->send('api/ExecBulkLicense', [[
            'tenantFilter' => $tenantFilter,
            'userIds' => [$userId],
            'LicenseOperation' => 'Remove',
            'Licenses' => [],
            'LicensesToRemove' => [['value' => $skuId]],
            'RemoveAllLicenses' => false,
            'ReplaceAllLicenses' => false,
        ]]);
    }

    /** @return array<int|string, mixed> */
    public function convertMailbox(string $tenantFilter, string $userPrincipalName, string $mailboxType): array
    {
        return $this->send('api/ExecConvertMailbox', [
            'tenantFilter' => $tenantFilter,
            'ID' => $userPrincipalName,
            'MailboxType' => $mailboxType,
        ]);
    }

    /** @return array<int|string, mixed> */
    public function setMailboxForwardingInternal(string $tenantFilter, string $userPrincipalName, string $targetUserPrincipalName, bool $keepCopy): array
    {
        return $this->send('api/ExecEmailForward', [
            'tenantFilter' => $tenantFilter,
            'userID' => $userPrincipalName,
            'ForwardInternal' => $targetUserPrincipalName,
            'ForwardExternal' => null,
            'forwardOption' => 'internalAddress',
            'KeepCopy' => $keepCopy ? 'true' : 'false',
        ]);
    }

    /** @return array<int|string, mixed> */
    public function setMailboxForwardingExternal(string $tenantFilter, string $userPrincipalName, string $externalSmtpAddress, bool $keepCopy): array
    {
        return $this->send('api/ExecEmailForward', [
            'tenantFilter' => $tenantFilter,
            'userID' => $userPrincipalName,
            'ForwardInternal' => null,
            'ForwardExternal' => $externalSmtpAddress,
            'forwardOption' => 'ExternalAddress',
            'KeepCopy' => $keepCopy ? 'true' : 'false',
        ]);
    }

    /** @return array<int|string, mixed> */
    public function disableMailboxForwarding(string $tenantFilter, string $userPrincipalName): array
    {
        return $this->send('api/ExecEmailForward', [
            'tenantFilter' => $tenantFilter,
            'userID' => $userPrincipalName,
            'ForwardInternal' => null,
            'ForwardExternal' => null,
            'forwardOption' => 'disabled',
            'KeepCopy' => 'false',
        ]);
    }

    /** @return array<int|string, mixed> */
    public function setMailboxGalVisibility(string $tenantFilter, string $userPrincipalName, bool $hidden): array
    {
        return $this->send('api/ExecHideFromGAL', [
            'tenantFilter' => $tenantFilter,
            'ID' => $userPrincipalName,
            'HideFromGAL' => $hidden,
        ]);
    }

    /** @return array<int|string, mixed> */
    public function setMailboxOutOfOffice(
        string $tenantFilter,
        string $userPrincipalName,
        string $state,
        ?string $internalMessage,
        ?string $externalMessage,
        ?string $startTime,
        ?string $endTime,
        ?string $timezone,
    ): array {
        $body = [
            'tenantFilter' => $tenantFilter,
            'userId' => $userPrincipalName,
            'AutoReplyState' => $state,
        ];

        if ($internalMessage !== null && trim($internalMessage) !== '') {
            $body['InternalMessage'] = $internalMessage;
        }
        if ($externalMessage !== null && trim($externalMessage) !== '') {
            $body['ExternalMessage'] = $externalMessage;
        }
        if ($state === 'Scheduled') {
            $body['StartTime'] = $startTime;
            $body['EndTime'] = $endTime;
        }
        if ($timezone !== null && trim($timezone) !== '') {
            $body['timezone'] = $timezone;
        }

        return $this->send('api/ExecSetOoO', $body);
    }

    /**
     * @param  array<int|string, mixed>  $body
     * @return array<int|string, mixed>
     */
    private function send(string $endpoint, array $body): array
    {
        $url = $this->endpointUrl($endpoint);
        $options = $this->safeRequestOptions($url);
        $token = $this->getToken();

        $response = Http::timeout(60)
            ->acceptJson()
            ->asJson()
            ->withOptions($options)
            ->withToken($token)
            ->post($url, $body);

        if ($response->failed()) {
            throw new CippClientException("CIPP write {$endpoint} failed: HTTP {$response->status()}");
        }

        return ['success' => true, 'status' => $response->status()];
    }

    private function getToken(): string
    {
        $tenantId = (string) ($this->config['tenant_id'] ?? '');
        $clientId = (string) ($this->config['client_id'] ?? '');
        $clientSecret = (string) ($this->config['client_secret'] ?? '');
        $applicationId = (string) (($this->config['application_id'] ?? null) ?: $clientId);

        if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
            throw new CippClientException('CIPP REST write client credentials are not configured');
        }

        $cacheKey = $this->tokenCacheKey($tenantId, $clientId, $applicationId);
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post($tokenUrl, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => "api://{$applicationId}/.default",
                ])
                ->throw();
        } catch (RequestException $e) {
            Log::error('[CippRestWriteClient] Token request failed', ['error' => $e->getMessage()]);
            throw new CippClientException("CIPP REST write OAuth token request failed: {$e->getMessage()}", $e->getCode(), $e);
        } catch (\Throwable $e) {
            Log::error('[CippRestWriteClient] Token request failed', ['error' => $e->getMessage()]);
            throw new CippClientException("CIPP REST write OAuth token request failed: {$e->getMessage()}", (int) $e->getCode(), $e);
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new CippClientException('CIPP REST write OAuth response missing access_token');
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 3600);
        $this->cache->put($cacheKey, $token, max(60, $expiresIn - 300));

        return $token;
    }

    private function tokenCacheKey(string $tenantId, string $clientId, string $applicationId): string
    {
        return self::TOKEN_CACHE_KEY.':'.sha1($tenantId.'|'.$clientId.'|'.$applicationId);
    }

    private function endpointUrl(string $endpoint): string
    {
        $apiUrl = (string) ($this->config['api_url'] ?? '');
        if ($apiUrl === '') {
            throw new CippClientException('CIPP API URL is not configured');
        }

        return rtrim($apiUrl, '/').'/'.ltrim($endpoint, '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function safeRequestOptions(string $url): array
    {
        $rejection = SafeUrlInspector::reject($url, $this->resolver);
        if ($rejection !== null) {
            throw new CippClientException(str_replace('Tactical API URL', 'CIPP API URL', $rejection));
        }

        $parts = parse_url($url);
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        $options = ['allow_redirects' => false];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $options;
        }

        $resolver = $this->resolver;
        $ips = $resolver($host);
        if ($ips === false || ! is_array($ips) || $ips === []) {
            throw new CippClientException("CIPP API host '{$host}' did not resolve (refused for safety).");
        }

        foreach ($ips as $ip) {
            if (! SafeUrlInspector::ipIsSafe($ip)) {
                throw new CippClientException("CIPP API host '{$host}' resolved to a private or reserved address ({$ip}); refused.");
            }
        }

        $options['curl'] = [CURLOPT_RESOLVE => [$host.':'.$port.':'.implode(',', $ips)]];

        return $options;
    }
}
