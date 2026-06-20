<?php

namespace LogLens\Security;

/**
 * Safe rendering of untrusted log content.
 *
 * HTML-escape FIRST, then translate ONLY SGR color sequences from a safelist
 * (cursor movement, OSC-8 hyperlinks, clipboard, mouse sequences are stripped),
 * then linkify http/https URLs only. Log content is never evaluated.
 *
 * The server returns structured tokens so the SPA renders without re-parsing
 * attacker-controlled bytes as HTML or RegExp.
 */
class SafeRenderer
{
    /** Allowed SGR codes → semantic class names the UI maps to colors. */
    private const SGR = [
        0 => 'reset', 1 => 'bold', 3 => 'italic', 4 => 'underline',
        30 => 'fg-black', 31 => 'fg-red', 32 => 'fg-green', 33 => 'fg-yellow',
        34 => 'fg-blue', 35 => 'fg-magenta', 36 => 'fg-cyan', 37 => 'fg-white',
        90 => 'fg-bright-black', 91 => 'fg-bright-red', 92 => 'fg-bright-green',
        93 => 'fg-bright-yellow', 94 => 'fg-bright-blue', 95 => 'fg-bright-magenta',
        96 => 'fg-bright-cyan', 97 => 'fg-bright-white',
        40 => 'bg-black', 41 => 'bg-red', 42 => 'bg-green', 43 => 'bg-yellow',
        44 => 'bg-blue', 45 => 'bg-magenta', 46 => 'bg-cyan', 47 => 'bg-white',
    ];

    /** HTML-escape for safe display. */
    public function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** Strip every ANSI control except SGR; return plain text + token spans. */
    public function stripAnsi(string $text): string
    {
        // Remove OSC sequences (hyperlinks/clipboard): ESC ] ... BEL or ST.
        // Also strip UNTERMINATED OSC sequences (no BEL/ST): run to newline or
        // end-of-string so e.g. "ESC]8;;https://evil" can't leak through.
        $text = preg_replace('/\x1b\][^\x07\x1b\n]*(?:\x07|\x1b\\\\|\n|$)/', '', $text) ?? $text;
        // Remove all CSI sequences that are NOT SGR (final byte != m).
        $text = preg_replace('/\x1b\[[0-9;?]*[A-Za-ln-z]/', '', $text) ?? $text;
        // Remove remaining single-char escapes.
        $text = preg_replace('/\x1b[@-Z\\\\-_]/', '', $text) ?? $text;

        return $text;
    }

    /**
     * Tokenize a line into spans the client can render. Each token:
     * {text, classes: string[]}. SGR state carries across tokens within a line.
     *
     * @return array<int,array{text:string,classes:string[]}>
     */
    public function tokenize(string $text): array
    {
        $text = $this->stripDangerousAnsi($text);
        $tokens = [];
        $classes = [];
        $offset = 0;
        $len = strlen($text);

        if (! preg_match_all('/\x1b\[([0-9;]*)m/', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [['text' => $this->escape($text), 'classes' => []]];
        }

        foreach ($matches[0] as $i => $match) {
            $seqStart = $match[1];
            $seqText = $match[0];
            if ($seqStart > $offset) {
                $tokens[] = ['text' => $this->escape(substr($text, $offset, $seqStart - $offset)), 'classes' => $classes];
            }
            $classes = $this->applySgr($classes, $matches[1][$i][0]);
            $offset = $seqStart + strlen($seqText);
        }
        if ($offset < $len) {
            $tokens[] = ['text' => $this->escape(substr($text, $offset)), 'classes' => $classes];
        }

        return array_values(array_filter($tokens, fn ($t) => $t['text'] !== ''));
    }

    /** Find http/https URLs and return their byte ranges for safe linkifying. */
    public function urlRanges(string $text): array
    {
        $ranges = [];
        if (preg_match_all('#\bhttps?://[^\s"\'<>\x00-\x1f]+#i', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$url, $pos]) {
                $ranges[] = ['start' => $pos, 'length' => strlen($url), 'url' => $url];
            }
        }

        return $ranges;
    }

    private function stripDangerousAnsi(string $text): string
    {
        // Also strip UNTERMINATED OSC sequences (no BEL/ST): run to newline or
        // end-of-string so e.g. "ESC]8;;https://evil" can't leak through.
        $text = preg_replace('/\x1b\][^\x07\x1b\n]*(?:\x07|\x1b\\\\|\n|$)/', '', $text) ?? $text;
        // Strip non-SGR CSI (keep ...m).
        $text = preg_replace('/\x1b\[[0-9;?]*[A-Za-ln-z]/', '', $text) ?? $text;

        return $text;
    }

    private function applySgr(array $classes, string $codes): array
    {
        if ($codes === '' || $codes === '0') {
            return [];
        }
        foreach (explode(';', $codes) as $code) {
            $n = (int) $code;
            if ($n === 0) {
                $classes = [];
            } elseif (isset(self::SGR[$n])) {
                $classes[] = 'ansi-' . self::SGR[$n];
            }
        }

        return array_values(array_unique($classes));
    }
}
