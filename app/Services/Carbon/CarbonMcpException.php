<?php

namespace App\Services\Carbon;

/**
 * A Carbon MCP call failed. The message is PSA-authored and carries an HTTP
 * status, a JSON-RPC error code or an exception class only: never a response
 * body, which may hold Carbon company or customer data (C-56, card PEYvh4NL).
 */
class CarbonMcpException extends \RuntimeException {}
