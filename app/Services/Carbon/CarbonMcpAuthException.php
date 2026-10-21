<?php

namespace App\Services\Carbon;

/**
 * PSA could not authenticate to Carbon MCP: no connection, a refresh that
 * failed, discovery or registration that failed, or an HTTP 401. Same message
 * rule as its parent: status / code / class only.
 */
class CarbonMcpAuthException extends CarbonMcpException {}
