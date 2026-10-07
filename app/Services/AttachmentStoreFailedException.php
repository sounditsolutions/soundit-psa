<?php

namespace App\Services;

/**
 * A stored attachment's file write returned false (#5709): the disk refused the write without
 * throwing. The message carries the attachment id only.
 */
class AttachmentStoreFailedException extends \RuntimeException {}
