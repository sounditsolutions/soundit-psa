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
 * Laravel's LineFormatter) reads it: jsonSerialize() for a JsonSerializable (a PSR-7 Uri is one,
 * and its jsonSerialize() returns the whole URL; #5841), then __toString() for an object that
 * has one and is not JsonSerializable (#5770), and otherwise every property, private and
 * protected ones included, a private property shadowed by a subclass's same-named one too
 * (#5832). Monolog prints only the public ones there; the scan reads more than the log line,
 * never less.
 *
 * A Throwable's frame arguments are not read by flattenForScan(): the log line prints a trace
 * through getTraceAsString(), which shows an array argument only as 'Array'. A reporter that
 * serialises getTrace() itself prints every argument when zend.exception_ignore_args is Off, so
 * frameArgumentsForScan() reads a frame's scalar and array arguments for a test that scans what
 * such a reporter sees (#5678); an object argument is named by its class only.
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
     * #5678: every argument of every frame of $e and of each getPrevious() link, as getTrace()
     * holds them. getTrace() holds function arguments only when zend.exception_ignore_args was
     * Off when the exception was created; with it On only an include/require frame keeps its
     * file path. A string or other scalar is written as it is and an array is walked key by key
     * (an Authorization header in an $options array is found). An object argument is written as
     * its class only and is not walked: frames hold the container, the test case and the client
     * itself, and walking those exhausts memory. A credential held inside an object argument is
     * therefore outside this scan.
     */
    protected static function frameArgumentsForScan(\Throwable $e): string
    {
        $parts = [];
        for ($link = $e, $links = 0; $link !== null && $links < 8; $link = $link->getPrevious(), $links++) {
            foreach ($link->getTrace() as $i => $frame) {
                foreach ($frame['args'] ?? [] as $n => $arg) {
                    $parts[] = $link::class."#{$i} ".($frame['function'] ?? '?')." arg {$n}: ".self::frameArgumentForScan($arg, 0);
                }
            }
        }

        return implode("\n", $parts);
    }

    private static function frameArgumentForScan(mixed $value, int $depth): string
    {
        if (is_string($value)) {
            return $value;
        }
        if ($value === null || is_scalar($value)) {
            return var_export($value, true);
        }
        if (is_array($value)) {
            if ($depth > 8) {
                return '<depth>';
            }
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = $key.' => '.self::frameArgumentForScan($item, $depth + 1);
            }

            return '['.implode(', ', $parts).']';
        }

        return get_debug_type($value);
    }

    /**
     * Every property of $object, private and protected included. A property is keyed by its bare
     * name; one whose bare name an earlier one already took (a parent's private property shadowed
     * by a subclass's property of the same name, #5832) is keyed 'DeclaringClass::name' ('*' for
     * protected, 'public' for public) instead, so both values are kept.
     *
     * @return array<string, mixed>
     */
    private static function allProperties(object $object): array
    {
        $out = [];
        foreach ((array) $object as $key => $item) {
            // (array) prefixes a private key with "\0Class\0" and a protected one with "\0*\0".
            $key = (string) $key;
            $name = (string) preg_replace('/^\0[^\0]*\0/', '', $key);
            if (array_key_exists($name, $out)) {
                // The prefix names the declaring class of a private property; a public or
                // protected one has no class in its key.
                $name = (preg_match('/^\0([^\0]+)\0/', $key, $declaring) ? $declaring[1] : 'public').'::'.$name;
            }
            $out[$name] = $item;
        }

        return $out;
    }
}
