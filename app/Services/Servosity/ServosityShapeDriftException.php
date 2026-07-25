<?php

namespace App\Services\Servosity;

/**
 * A Servosity response failed the documented-envelope check (official OpenAPI:
 * DRF pagination requires `count` + `results`). Deliberately distinct from
 * ServosityClientException: "the API answered with a shape we do not recognise"
 * must never be read as "the API answered: zero rows" — a degraded read must
 * SCREAM (CLAUDE.md vendor-shape rules). The message crosses into logs, so it
 * must never carry URLs, tokens, or response content — endpoint names only.
 */
class ServosityShapeDriftException extends \RuntimeException {}
