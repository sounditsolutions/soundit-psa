<?php

namespace App\Http\Middleware;

use App\Services\Mcp\PortalMcpIdentityResolver;
use App\Support\McpConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the portal MCP server (`/api/mcp/portal`).
 *
 * Two-part trust model:
 *  1. A shared bearer token proves the caller is the authorised bridge (the
 *     client Teams agent connector). Without it, the request is rejected — so
 *     nobody can hit the endpoint and impersonate a portal user by guessing an
 *     object id.
 *  2. The end user is identified per-request by the `X-Mcp-Portal-Object-Id`
 *     header (the Teams sender's Entra/Azure AD Object ID, taken from the
 *     Bot-Framework-validated `from.aadObjectId`). It is resolved to a portal
 *     {@see \App\Models\Person} and stashed on the request as `mcp_portal_person`
 *     (nullable). The identity NEVER comes from tool arguments, so the model
 *     cannot spoof which user it is acting as.
 *
 * `initialize` / `tools/list` work without a resolved person (they are static);
 * `tools/call` requires one and the controller fails closed when it is absent.
 */
class VerifyMcpPortalToken
{
    public const OBJECT_ID_HEADER = 'X-Mcp-Portal-Object-Id';

    public function __construct(
        private readonly PortalMcpIdentityResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! McpConfig::isPortalEnabled()) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32001, 'message' => 'Portal MCP server not configured'],
                'id' => null,
            ], 503);
        }

        $header = $request->header('Authorization', '');
        if (! preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return $this->unauthorized();
        }

        if (! McpConfig::resolvePortalToken(trim($m[1]))) {
            return $this->unauthorized();
        }

        // Resolve the Teams sender to a portal Person (fail-closed, nullable).
        $person = $this->resolver->resolve($request->header(self::OBJECT_ID_HEADER));
        $request->attributes->set('mcp_portal_person', $person);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32001, 'message' => 'Unauthorized'],
            'id' => null,
        ], 401);
    }
}
