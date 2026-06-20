<?php

namespace LogLens\Parsing;

use LogLens\Contracts\Parser;
use LogLens\Parsing\Parsers\HttpAccessParser;
use LogLens\Parsing\Parsers\JsonLinesParser;
use LogLens\Parsing\Parsers\LaravelLogParser;
use LogLens\Parsing\Parsers\RegexParser;

/**
 * Factory for the built-in opcodes-parity parser set. Keeping the
 * regex/level definitions in one place makes the auto-detection ranking and
 * the migration-parity claim auditable.
 */
final class BuiltInFormats
{
    /**
     * @return array<string,Parser>
     */
    public static function all(array $vendorMarkers = []): array
    {
        return [
            // Horizon writes through Laravel's logger, so the 'laravel' parser
            // already covers it — a separate 'horizon' entry was a dead duplicate
            // whose id() collided with 'laravel'. The bracketed legacy format
            // below is the only one that needs its own parser.
            'laravel' => new LaravelLogParser($vendorMarkers ?: ['/vendor/', '\\vendor\\']),
            'json' => new JsonLinesParser(),
            'horizon_old' => new RegexParser(
                'horizon_old',
                '/^\[(?<datetime>[^\]]+)\]\[(?<uuid>[0-9a-f-]{8,})\]\s*(?<level>\w+)?:?\s*(?<message>.*)$/su',
                ['Processing' => 'info', 'Processed' => 'info', 'Failed' => 'error'],
                'info',
                '['
            ),
            'http_access' => new HttpAccessParser(),
            'apache_error' => new RegexParser(
                'apache_error',
                '/^\[(?<datetime>[^\]]+)\]\s+\[(?:[\w.]+:)?(?<level>\w+)\]\s+(?:\[pid\s+\d+(?::tid\s+\d+)?\]\s+)?(?<message>.*)$/su',
                [],
                'error',
                '['
            ),
            'nginx_error' => new RegexParser(
                'nginx_error',
                '#^(?<datetime>\d{4}/\d{2}/\d{2}\s+\d{2}:\d{2}:\d{2})\s+\[(?<level>\w+)\]\s+(?<message>.*)$#su',
                ['warn' => 'warning', 'emerg' => 'emergency', 'crit' => 'critical'],
                'error'
            ),
            'php_fpm' => new RegexParser(
                'php_fpm',
                '/^\[(?<datetime>\d{1,2}-\w{3}-\d{4}\s+\d{2}:\d{2}:\d{2}(?:\s+\w+)?)\]\s+(?<level>WARNING|NOTICE|ERROR|ALERT|DEBUG|INFO|FATAL):\s+(?<message>.*)$/su',
                ['FATAL' => 'critical'],
                'notice',
                '['
            ),
            'postgres' => new RegexParser(
                'postgres',
                '/^(?<datetime>\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:\s+\w+)?)\s+\[\d+\]\s+(?<level>LOG|ERROR|FATAL|PANIC|WARNING|DEBUG|NOTICE|INFO|STATEMENT|DETAIL|HINT):\s+(?<message>.*)$/su',
                ['LOG' => 'info', 'PANIC' => 'emergency', 'STATEMENT' => 'debug', 'DETAIL' => 'debug', 'HINT' => 'debug'],
                'info'
            ),
            'redis' => new RegexParser(
                'redis',
                '/^\d+:[A-Z]\s+(?<datetime>\d{1,2}\s+\w{3}\s+\d{4}\s+\d{2}:\d{2}:\d{2}\.\d+)\s+(?<level>[.\-*#])\s+(?<message>.*)$/su',
                ['.' => 'debug', '-' => 'info', '*' => 'notice', '#' => 'warning'],
                'info'
            ),
            'supervisor' => new RegexParser(
                'supervisor',
                '/^(?<datetime>\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}(?:,\d+)?)\s+(?<level>CRIT|ERRO|WARN|INFO|DEBG|TRAC|BLAT)\s+(?<message>.*)$/su',
                ['CRIT' => 'critical', 'ERRO' => 'error', 'WARN' => 'warning', 'DEBG' => 'debug', 'TRAC' => 'debug', 'BLAT' => 'debug'],
                'info'
            ),
        ];
    }
}
