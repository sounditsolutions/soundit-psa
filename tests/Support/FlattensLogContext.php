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
 * array is walked key by key, and a Throwable is written as its class, its message, the response
 * body when it has one (GraphClientException::getResponseBody()), and then the same for every
 * getPrevious() link. Any other object is read in the order Monolog's NormalizerFormatter (under
 * Laravel's LineFormatter) reads it: jsonSerialize() for a JsonSerializable, then __toString()
 * for an object that has one (a PSR-7 Uri prints its whole URL in the log line; #5770), and
 * otherwise every property, private and protected ones included. Monolog prints only the public
 * ones there; the scan reads more than the log line, never less.
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
            if ($value instanceof \JsonSerializable) {
                $value = $value->jsonSerialize();
            } elseif (method_exists($value, '__toString')) {
                try {
                    return $value::class.': '.$value->__toString();
                } catch (\Throwable) {
                    $value = self::allProperties($value);
                }
            } else {
                $value = self::allProperties($value);
            }
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

    /**
     * Every property of $object, private and protected included, keyed by its bare name.
     *
     * @return array<string, mixed>
     */
    private static function allProperties(object $object): array
    {
        $out = [];
        foreach ((array) $object as $key => $item) {
            // (array) prefixes a private key with "\0Class\0" and a protected one with "\0*\0".
            $out[(string) preg_replace('/^\0[^\0]*\0/', '', (string) $key)] = $item;
        }

        return $out;
    }
}
