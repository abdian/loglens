<?php

namespace LogLens\Parsing;

use LogLens\Support\Utf8;

/**
 * Regex-free, brace-depth character scanner that splits a Laravel message line
 * into its plain message and up to two trailing JSON objects:
 *
 *   "User authenticated. {"auth_id":27} {"trace_id":"abc"}"
 *      message ───────────┘ context ────┘ extra ───────────┘
 *
 * Laravel 11+ appends the Context-facade payload as a *second* adjacent object
 *. A character walk avoids the PCRE-JIT stack-crash class that
 * regex tail-matching of big JSON triggers.
 */
final class JsonTailScanner
{
    /**
     * @return array{message:string, context:?array, extra:?array}
     */
    public static function split(string $line): array
    {
        $line = rtrim($line);
        $tails = [];

        // Pull JSON objects off the tail, right to left, up to two.
        for ($i = 0; $i < 2; $i++) {
            $obj = self::trailingObject($line);
            if ($obj === null) {
                break;
            }
            array_unshift($tails, $obj['json']);
            $line = rtrim(substr($line, 0, $obj['start']));
        }

        $context = isset($tails[0]) ? Utf8::jsonDecode($tails[0]) : null;
        $extra = isset($tails[1]) ? Utf8::jsonDecode($tails[1]) : null;

        // If the first decoded fine but a "second" was actually the only one,
        // keep ordering: first tail = context, second tail = extra.
        return [
            'message' => $line,
            'context' => $context,
            'extra' => $extra,
        ];
    }

    /**
     * Find a balanced {...} object anchored at the end of $line (after trimming
     * trailing whitespace). Returns its start offset and raw JSON, or null.
     *
     * @return array{start:int, json:string}|null
     */
    private static function trailingObject(string $line): ?array
    {
        $len = strlen($line);
        $end = $len - 1;
        while ($end >= 0 && ctype_space($line[$end])) {
            $end--;
        }
        if ($end < 0 || $line[$end] !== '}') {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $end; $i >= 0; $i--) {
            $ch = $line[$i];

            if ($inString) {
                // Walking backwards: a quote ends the string unless it's escaped
                // by an odd run of backslashes preceding it.
                if ($ch === '"' && ! self::escapedBackwards($line, $i)) {
                    $inString = false;
                }
                continue;
            }

            if ($ch === '"') {
                $inString = true;
                continue;
            }
            if ($ch === '}') {
                $depth++;
            } elseif ($ch === '{') {
                $depth--;
                if ($depth === 0) {
                    $json = substr($line, $i, $end - $i + 1);
                    // A balanced run of braces that cleanly decodes as a JSON
                    // object is the tail; anything else (prose, partial) fails
                    // the decode and is treated as plain message text.
                    if (Utf8::jsonDecode($json) !== null) {
                        return ['start' => $i, 'json' => $json];
                    }

                    return null;
                }
            }
        }

        return null;
    }

    private static function escapedBackwards(string $s, int $quotePos): bool
    {
        $bs = 0;
        $j = $quotePos - 1;
        while ($j >= 0 && $s[$j] === '\\') {
            $bs++;
            $j--;
        }

        return ($bs % 2) === 1;
    }
}
