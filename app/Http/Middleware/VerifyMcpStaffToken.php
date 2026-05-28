<?php

namespace App\Http\Middleware;

use App\Support\McpConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyMcpStaffToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = McpConfig::staffToken();
        if (! $expected) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32001, 'message' => 'Staff MCP server not configured'],
                'id' => null,
            ], 503);
        }

        $header = $request->header('Authorization', '');
        if (! preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return $this->unauthorized();
        }

        if (! hash_equals($expected, trim($m[1]))) {
            return $this->unauthorized();
        }

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
