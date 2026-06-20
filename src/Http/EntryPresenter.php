<?php

namespace LogLens\Http;

use LogLens\Indexing\IndexedEntry;
use LogLens\Indexing\Level;
use LogLens\Parsing\ParsedEntry;
use LogLens\Search\AstEvaluator;
use LogLens\Search\Node;
use LogLens\Security\Redactor;
use LogLens\Security\SafeRenderer;
use LogLens\Support\Utf8;

/**
 * Converts parsed entries into API/UI payloads, applying display-time
 * redaction, safe ANSI/HTML tokenization and oversized-entry truncation
 * uniformly across browsing, search, tail and export paths. The single funnel guarantees redaction can't be bypassed.
 */
class EntryPresenter
{
    public function __construct(
        private Redactor $redactor,
        private SafeRenderer $renderer,
        private int $maxDisplayBytes = 65536
    ) {
    }

    /**
     * @param  array  $opts  ['highlights'=>array, 'raw'=>string, 'full'=>bool]
     */
    public function present(?IndexedEntry $indexed, ParsedEntry $parsed, array $opts = []): array
    {
        $raw = Utf8::sanitize($opts['raw'] ?? $parsed->raw);
        $full = $opts['full'] ?? false;

        // Redact BEFORE truncating so a secret straddling the display cap can't
        // leak its head (the pattern would otherwise fail to match the cut-off
        // token). Redact a window past the cap — far longer than any secret — so
        // boundary matches are whole, then truncate. Done once and reused below.
        $truncated = false;
        if (! $full && strlen($raw) > $this->maxDisplayBytes) {
            $window = substr($raw, 0, $this->maxDisplayBytes + 8192);
            $display = substr($this->redactor->redact($window), 0, $this->maxDisplayBytes);
            $truncated = true;
        } else {
            $display = $this->redactor->redact($raw);
        }

        $message = $this->redactor->redact(Utf8::sanitize($parsed->message));
        $level = $indexed?->level ?? $parsed->level;

        // Highlights are computed here, against the FINAL redacted message, in
        // character offsets — so the UI can apply them without re-interpreting
        // the user's pattern and without byte/char drift.
        $highlights = $opts['highlights'] ?? [];
        if (isset($opts['highlight_node']) && $opts['highlight_node'] instanceof Node) {
            $highlights = (new AstEvaluator((bool) ($opts['case_sensitive'] ?? false)))
                ->highlightRanges($opts['highlight_node'], $message);
        }

        return [
            'seq' => $indexed?->seq,
            'offset' => $indexed?->offset,
            'length' => $indexed?->length,
            'timestamp' => $parsed->timestamp ?? $indexed?->timestamp,
            'datetime' => $this->iso($parsed->timestamp ?? $indexed?->timestamp),
            'level' => Level::name($level),
            'level_value' => $level,
            'channel' => $parsed->channel,
            'environment' => $parsed->environment,
            'message' => $message,
            'title' => mb_substr(strtok($message, "\n") ?: $message, 0, 200),
            'truncated' => $truncated,
            'raw' => $display,
            'tokens' => $this->renderer->tokenize($display),
            'context' => $parsed->context ? $this->redactor->redactArray($parsed->context) : null,
            'extra' => $parsed->extra ? $this->redactor->redactArray($parsed->extra) : null,
            'exception' => $parsed->isException() ? $this->exception($parsed) : null,
            'highlights' => $highlights,
            // String to survive the JS Number round-trip (see GroupsController).
            'fingerprint' => $indexed?->fpApp !== null ? (string) $indexed->fpApp : null,
        ];
    }

    private function exception(ParsedEntry $parsed): array
    {
        return [
            'class' => $parsed->exceptionClass,
            'message' => $this->redactor->redact((string) $parsed->exceptionMessage),
            'throw_file' => $parsed->throwFile,
            'throw_line' => $parsed->throwLine,
            'app_frame' => $parsed->appFrame,
            'frames' => array_map(fn ($f) => [
                'file' => $f['file'],
                'line' => $f['line'],
                'call' => $f['call'],
                'vendor' => $f['vendor'],
            ], $parsed->frames),
        ];
    }

    private function iso(?int $ts): ?string
    {
        return $ts !== null ? gmdate('Y-m-d\TH:i:s\Z', $ts) : null;
    }
}
