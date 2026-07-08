<?php

namespace App\Services\Tactical\Actions;

use RuntimeException;

/**
 * Thrown by TacticalAction::validateParams() when the supplied params are
 * invalid (missing/ill-typed/unsafe). The bus (T5) catches this and records a
 * `rejected` audit row WITHOUT executing the action (spec §11/amendment m2).
 */
class InvalidActionParams extends RuntimeException {}
