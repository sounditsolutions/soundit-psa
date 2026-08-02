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
 *
 * THE INVARIANT THIS CLASS CARRIES, stated because nothing enforces it:
 * EVERY instance must be constructed with OPERATOR-AUTHORED TEXT — a literal
 * written to be read by a customer. Never with $e->getMessage(), never with
 * text derived from an exception, a driver, a vendor response or any other
 * input.
 *
 * That is not hypothetical bookkeeping. PortalChatbotService::sendMessage()
 * catches \Throwable around the AI call and re-throws AS THIS TYPE. Today it
 * passes a constant, so nothing leaks. But that conversion is one token away
 * from interpolating the caught exception's message — and because this type is
 * on the controller's allowlist, doing so would route it straight back to the
 * customer, reopening psa #378 through the very class that closed it.
 *
 * So: when you throw this, the string is one you wrote. When you catch
 * something and want to surface it, log the original and throw this with your
 * own words.
 */
class PortalChatbotUserFacingException extends \RuntimeException {}
