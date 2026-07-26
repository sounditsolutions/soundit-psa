<?php

namespace App\Services\Servosity;

/**
 * A Servosity response failed a documented-shape check: the DRF envelope
 * (official OpenAPI: `count` + `results` REQUIRED), a documented row/field
 * type, or JSON itself (an unparseable body is drift too, psa-z30dv.7 — never
 * collapsed to a clean []). "The API answered with a shape we do not
 * recognise" must never be read as "the API answered: zero rows" — a degraded
 * read must SCREAM (CLAUDE.md vendor-shape rules). It extends
 * ServosityClientException so every legacy vendor-failure catch (deployment
 * flows, isHealthy()) still contains it, while the read surface catches this
 * type specifically to report `schema_drift` rather than `unavailable`. The
 * message crosses into logs, so it must never carry URLs, tokens, or response
 * content — endpoint names only.
 */
class ServosityShapeDriftException extends ServosityClientException {}
