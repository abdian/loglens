<?php

namespace LogLens\Parsing;

use LogLens\Parsing\Parsers\RegexParser;

/**
 * Migration adapter: wrap an opcodesio/log-viewer `Log`-subclass so existing
 * custom parsers work unmodified. We avoid a hard
 * dependency on opcodes by duck-typing the regex out of the class via a few
 * known shapes (`$regex` static, `regex()` / `regexPattern()` methods).
 */
final class OpcodesAdapter
{
    public static function adapt(string $class, ?string $id = null): ?RegexParser
    {
        if (! class_exists($class)) {
            return null;
        }
        $id = $id ?: 'opcodes_' . strtolower((new \ReflectionClass($class))->getShortName());

        $pattern = self::extractRegex($class);
        if ($pattern === null) {
            return null;
        }

        return new RegexParser($id, self::ensureNamedGroups($pattern));
    }

    private static function extractRegex(string $class): ?string
    {
        $ref = new \ReflectionClass($class);

        foreach (['regex', 'regexPattern'] as $method) {
            if ($ref->hasMethod($method)) {
                try {
                    $instance = $ref->newInstanceWithoutConstructor();
                    $value = $instance->{$method}();
                    if (is_string($value) && $value !== '') {
                        return $value;
                    }
                } catch (\Throwable $e) {
                    // fall through
                }
            }
        }

        foreach (['regex', 'pattern'] as $prop) {
            if ($ref->hasProperty($prop)) {
                $p = $ref->getProperty($prop);
                $p->setAccessible(true);
                $value = $p->isStatic() ? $p->getValue() : $p->getValue($ref->newInstanceWithoutConstructor());
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /** Map opcodes' positional groups (datetime/level/text) to our names. */
    private static function ensureNamedGroups(string $pattern): string
    {
        if (strpos($pattern, '(?<datetime>') !== false || strpos($pattern, '(?P<datetime>') !== false) {
            return $pattern;
        }
        // opcodes commonly names the body group "text"; alias it to "message".
        return str_replace(['(?<text>', '(?P<text>'], '(?<message>', $pattern);
    }
}
