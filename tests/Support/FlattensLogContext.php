<?php

namespace Tests\Support;

/**
 * #5733 / #5737: a log record's message and context as one plain string a needle scan can read.
 *
 * json_encode() is the wrong tool for that scan, for two reasons:
 * - it escapes '/' as '\/', so a 'users/' needle never matches an encoded value;
 * - it renders a Throwable (or any object without public properties) as {}, so an exception
 *   object put in a context is invisible to the scan.
 *
 * flattenForScan() walks the value instead. Scalars are written as they are (no escaping), an
 * array or a plain object is walked key by key, and a Throwable is written as its class, its
 * message, the response body when it has one (GraphClientException::getResponseBody()), and
 * then the same for every getPrevious() link.
 */
trait FlattensLogContext
{
    protected static function flattenForScan(mixed $value, int $depth = 0): string
    {
        if ($depth > 12) {
            return '<depth>';
        }
        if (is_string($value)) {
            return $value;
        }
        if ($value === null || is_scalar($value)) {
            return var_export($value, true);
        }
        if ($value instanceof \Throwable) {
            $parts = [];
            for ($e = $value, $links = 0; $e !== null && $links < 8; $e = $e->getPrevious(), $links++) {
                $parts[] = $e::class.': '.$e->getMessage();
                if (method_exists($e, 'getResponseBody')) {
                    $parts[] = self::flattenForScan($e->getResponseBody(), $depth + 1);
                }
            }

            return implode(' | ', $parts);
        }
        if (is_object($value)) {
            $value = $value instanceof \JsonSerializable ? $value->jsonSerialize() : get_object_vars($value);
            if (! is_array($value)) {
                return self::flattenForScan($value, $depth + 1);
            }
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = $key.' => '.self::flattenForScan($item, $depth + 1);
            }

            return '['.implode(', ', $parts).']';
        }

        return get_debug_type($value);
    }
}
