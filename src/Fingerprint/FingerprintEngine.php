<?php

namespace LogLens\Fingerprint;

use LogLens\Parsing\ParsedEntry;
use LogLens\Support\Hash;

/**
 * Deterministic, two-tier error fingerprints computed in the index pass
 *. Deterministic + order-independent so
 * parallel segment indexing and daily-rotated files aggregate identically.
 *
 * Tier 1 (exceptions): fp_app = hash(class | app_frame), fp_sys = hash(class |
 * throw_site). Tier 2 (plain): hash(normalized template). Per-class rules and
 * a deploy-stability toggle (drop line numbers) are configurable.
 */
final class FingerprintEngine
{
    private bool $deployStability;

    private array $rules;

    public const KIND_EXCEPTION = 1;
    public const KIND_MESSAGE = 2;

    public function __construct(array $config = [])
    {
        $this->deployStability = (bool) ($config['deploy_stability'] ?? false);
        $this->rules = $config['rules'] ?? [];
    }

    /**
     * @return array{app:?int, sys:?int, title:?string, kind:int}
     */
    public function compute(ParsedEntry $entry): array
    {
        if ($entry->isException()) {
            return $this->exceptionFingerprint($entry);
        }

        return $this->messageFingerprint($entry);
    }

    private function exceptionFingerprint(ParsedEntry $entry): array
    {
        $class = $entry->exceptionClass ?? 'Exception';
        $strategy = $this->rules[$class] ?? 'class_frame';

        $appFrame = $entry->appFrame ?? $entry->throwFile . ':' . $entry->throwLine;
        $throwSite = $entry->throwFile . ':' . $entry->throwLine;

        if ($this->deployStability) {
            $appFrame = $this->stripLine($appFrame);
            $throwSite = $this->stripLine($throwSite);
        }

        switch ($strategy) {
            case 'class':
                $appKey = $class;
                $title = $class;
                break;
            case 'class_message':
                $tpl = MessageTemplate::normalize($entry->exceptionMessage ?? $entry->message);
                $appKey = $class . '|' . $tpl;
                $title = $class . ': ' . $tpl;
                break;
            case 'class_sql':
                // QueryException: group by class + normalized SQL.
                $sql = $this->extractSql($entry);
                $appKey = $class . '|sql:' . MessageTemplate::normalize($sql);
                $title = $class . ': ' . MessageTemplate::normalize($sql);
                break;
            case 'class_frame':
            default:
                $appKey = $class . '|' . $appFrame;
                $title = $class . ' @ ' . $appFrame;
                break;
        }

        return [
            'app' => Hash::int64($appKey),
            'sys' => Hash::int64($class . '|' . $throwSite),
            'title' => $title,
            'kind' => self::KIND_EXCEPTION,
        ];
    }

    private function messageFingerprint(ParsedEntry $entry): array
    {
        $firstLine = $entry->message;
        $nl = strpos($firstLine, "\n");
        if ($nl !== false) {
            $firstLine = substr($firstLine, 0, $nl);
        }

        $template = MessageTemplate::normalize($firstLine);

        return [
            'app' => Hash::int64('msg|' . $template),
            'sys' => null,
            'title' => $template,
            'kind' => self::KIND_MESSAGE,
        ];
    }

    private function extractSql(ParsedEntry $entry): string
    {
        $msg = $entry->exceptionMessage ?? $entry->message;
        // QueryException messages embed "(SQL: ...)" tail.
        if (preg_match('/\(SQL:\s*(.+?)\)\s*$/s', $msg, $m)) {
            return $m[1];
        }

        return $msg;
    }

    private function stripLine(string $frame): string
    {
        return preg_replace('/:\d+$/', '', $frame) ?? $frame;
    }
}
