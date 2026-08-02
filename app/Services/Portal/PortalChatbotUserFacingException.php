<?php

namespace App\Services\Portal;

/**
 * A guard failure whose message was WRITTEN to be shown to a portal user.
 *
 * The distinction this class exists to make is an allowlist, not a category.
 * The controller previously caught `\RuntimeException` and echoed its message
 * into a 422 body, on the reasoning that the only RuntimeExceptions this
 * service throws are the four operator-authored strings below. That reasoning
 * was true about this service and false about the class hierarchy:
 * `Illuminate\Database\QueryException extends PDOException extends
 * RuntimeException`, so a database fault inside sendMessage() was caught by
 * that arm and its getMessage() — the statement text and its bound values —
 * returned to the customer.
 *
 * Marking the safe messages, rather than naming the unsafe ones, fails closed:
 * anything not thrown deliberately for display falls through to the
 * controller's \Throwable arm, which reports it and returns a fixed string.
 */
class PortalChatbotUserFacingException extends \RuntimeException {}
