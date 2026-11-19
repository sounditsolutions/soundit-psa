<?php

namespace App\Services\Mcp;

use App\Models\Client;
use App\Services\Cipp\CippMcpAuthException;
use App\Services\Cipp\CippMcpToolRelay;
use App\Services\Cipp\CippToolContract;
use App\Services\Cipp\HandlesCippTools;
use App\Support\CippConfig;
use Illuminate\Support\Facades\Log;

/**
 * cipp_list_service_health_issues behind the staff MCP controller's explicit-grant
 * gate. Both transports: the MCP relay when it is switched on, otherwise (or when
 * the relay cannot sign in) the direct CIPP API. A relay sign-in failure or a 401
 * from the relay therefore means two upstream calls: the relay, then the direct
 * API (no direct call is made when the REST client cannot be built). The relay
 * path is built to the direct leg's measured shape, not measured on a live relay;
 * the relay's reply shape is read from vendor source, not measured.
 */
class CippServiceHealthToolExecutor
{
    use HandlesCippTools;

    public function __construct(protected ?int $clientId) {}

    /**
     * Every refusal, from either leg or from the checks before them, ends with the
     * sentence that it is NOT an empty result.
     */
    public function execute(array $input): array
    {
        return CippToolContract::serviceHealthAnswer($this->cippListServiceHealthIssues($input));
    }

    protected function cippScopeClient(): ?Client
    {
        return $this->clientId === null ? null : Client::find($this->clientId);
    }

    protected function cippLogPrefix(): string
    {
        return '[MCP]';
    }

    protected function cippMcpRelay(string $toolName, array $input, ?float $deadline): ?array
    {
        if ($toolName !== CippToolContract::SERVICE_HEALTH_TOOL) {
            return null;
        }

        try {
            if (! CippConfig::isMcpRelayEnabled()) {
                return null;
            }
        } catch (\Throwable $e) {
            Log::warning('[MCP] CIPP MCP relay settings could not be read; the relay does not answer this read', [
                'tool' => $toolName,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return null;
        }

        try {
            return app(CippMcpToolRelay::class)->execute($toolName, $input, $this->cippScopeClient(), $this->clientId, $deadline);
        } catch (CippMcpAuthException $e) {
            // CIPP did not run the query; the direct path answers instead when the REST
            // client can be built (HandlesCippTools::cippDispatch()).
            Log::warning('[MCP] CIPP MCP relay could not sign in; the relay does not answer this read', [
                'tool' => $toolName,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return null;
        }
    }
}
