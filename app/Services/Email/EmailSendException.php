<?php

namespace App\Services\Email;

use RuntimeException;

/**
 * Thrown by the legacy sendTicketReplyNote() shim for the maybe-sent outcomes
 * (Indeterminate / SentUnrecorded), preserving the pre-psa-330 contract in which
 * those surfaced as a throwable to callers that catch \Throwable.
 */
class EmailSendException extends RuntimeException {}
