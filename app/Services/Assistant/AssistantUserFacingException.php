<?php

namespace App\Services\Assistant;

/**
 * A guard failure whose message was WRITTEN to be shown to the staff user.
 *
 * An allowlist, not a category. AssistantController::send() caught
 * `\RuntimeException` and echoed its message into a 422 body, on the reasoning
 * that the only RuntimeExceptions this service throws are the operator-authored
 * strings below. True of the service, false of the class hierarchy:
 * `Illuminate\Database\QueryException extends PDOException extends
 * RuntimeException`, so a database fault inside sendMessage() was caught by that
 * arm and its getMessage() — the statement and its bound values — returned to
 * the caller.
 *
 * Same defect and same remedy as the client-portal instance (psa #378); the
 * surface here is authenticated staff rather than a customer, which is why it is
 * tracked and rated separately rather than as that issue's twin.
 *
 * Marking the safe messages, rather than naming the unsafe ones, fails closed:
 * anything not thrown deliberately for display falls through to the controller's
 * \Throwable arm, which logs it and returns a fixed string.
 */
class AssistantUserFacingException extends \RuntimeException {}
