<?php

namespace LogLens\Search;

use LogLens\Indexing\Level;

/**
 * Evaluates a query AST against a single entry's fields. This is the
 * correctness layer: whatever the index tier narrows candidates to, the final
 * match decision (and the highlight ranges) come from here, so results are
 * identical across FTS5 / LIKE / scan tiers.
 *
 * @phpstan-type Ctx array{text:string,level:int,ts:?int,channel:?string,env:?string,file:?string,context:?array}
 */
class AstEvaluator
{
    public function __construct(private bool $caseSensitive = false)
    {
    }

    public function matches(Node $node, array $ctx): bool
    {
        switch ($node->type) {
            case 'all':
                return true;
            case 'and':
                foreach ($node->children as $c) {
                    if (! $this->matches($c, $ctx)) {
                        return false;
                    }
                }

                return true;
            case 'or':
                foreach ($node->children as $c) {
                    if ($this->matches($c, $ctx)) {
                        return true;
                    }
                }

                return false;
            case 'not':
                return ! $this->matches($node->children[0], $ctx);
            case 'term':
            case 'phrase':
                // Match against the augmented haystack (FTS superset) when
                // present so bare level/date/channel terms resolve; fall back
                // to raw text for callers that don't supply one.
                return $this->matchText($node, $ctx['haystack'] ?? $ctx['text']);
            case 'regex':
                return $this->matchRegex($node, $ctx['text']);
            case 'level':
                return $this->matchLevel($node, $ctx['level']);
            case 'time':
                return $this->matchTime($node, $ctx['ts']);
            case 'field':
                return $this->matchField($node, $ctx);
        }

        return false;
    }

    private function matchText(Node $node, string $text): bool
    {
        $needle = $node->prop('value');
        if ($needle === '') {
            return true;
        }
        if ($node->type === 'term' && $node->prop('wildcard')) {
            // prefix wildcard: match the needle followed by word chars
            return $this->caseSensitive
                ? strpos($text, $needle) !== false
                : stripos($text, $needle) !== false;
        }

        return $this->caseSensitive
            ? strpos($text, $needle) !== false
            : mb_stripos($text, $needle) !== false;
    }

    private function matchRegex(Node $node, string $text): bool
    {
        $pattern = $this->buildRegex($node);

        return $pattern !== null && @preg_match($pattern, $text) === 1;
    }

    private function matchLevel(Node $node, int $level): bool
    {
        $ords = Level::resolveComparison($node->prop('op'), $node->prop('value'));

        return in_array($level, $ords, true);
    }

    private function matchTime(Node $node, ?int $ts): bool
    {
        if ($ts === null) {
            return false;
        }

        return $node->prop('field') === 'after' ? $ts >= $node->prop('value') : $ts <= $node->prop('value');
    }

    private function matchField(Node $node, array $ctx): bool
    {
        $field = $node->prop('field');
        $value = $node->prop('value');
        // An empty field value (e.g. `channel:`) is an existence test, not a
        // match-everything: require the field to be present and non-empty.
        if ($value === '') {
            return $this->fieldPresent($field, $ctx);
        }
        $candidates = [];

        switch ($field) {
            case 'channel':
                $candidates[] = $ctx['channel'];
                break;
            case 'env':
            case 'environment':
                $candidates[] = $ctx['env'];
                break;
            case 'file':
                $candidates[] = $ctx['file'];
                break;
            default:
                // context field: look up the key in the entry context/extra.
                $ctxData = $ctx['context'] ?? [];
                if (is_array($ctxData) && array_key_exists($field, $ctxData)) {
                    $candidates[] = is_scalar($ctxData[$field]) ? (string) $ctxData[$field] : json_encode($ctxData[$field]);
                }
        }

        foreach ($candidates as $c) {
            if ($c === null) {
                continue;
            }
            if ($this->caseSensitive ? $c === $value : strcasecmp($c, $value) === 0) {
                return true;
            }
            if ($this->caseSensitive ? strpos($c, $value) !== false : stripos($c, $value) !== false) {
                return true;
            }
        }

        return false;
    }

    private function fieldPresent(string $field, array $ctx): bool
    {
        switch ($field) {
            case 'channel':
                return ! empty($ctx['channel']);
            case 'env':
            case 'environment':
                return ! empty($ctx['env']);
            case 'file':
                return ! empty($ctx['file']);
            default:
                $data = $ctx['context'] ?? [];

                return is_array($data) && array_key_exists($field, $data);
        }
    }

    private function buildRegex(Node $node): ?string
    {
        $pattern = $node->prop('value');
        $flags = $node->prop('flags', '');
        $flags = preg_replace('/[^imsxuU]/', '', $flags);
        $delim = '#';
        if (strpos($pattern, '#') !== false) {
            $delim = '~';
            $pattern = str_replace('~', '\~', $pattern);
        }
        $built = $delim . $pattern . $delim . $flags;

        return @preg_match($built, '') === false ? null : $built;
    }

    /**
     * Server-computed highlight ranges in CHARACTER offsets, matched with the
     * same Unicode-aware case folding as matchText() — so a matched entry never
     * yields zero highlights and the ranges align with the displayed string in
     * the (UTF-16) browser. Compute against the SAME string the UI renders
     * (the redacted message), passed in by the presenter.
     *
     * @return array<int,array{start:int,length:int}>
     */
    public function highlightRanges(Node $node, string $text): array
    {
        $needles = [];
        $regexes = [];
        $this->collectNeedles($node, $needles, $regexes);

        $ranges = [];
        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }
            $needleLen = mb_strlen($needle);
            $offset = 0;
            while (($pos = $this->caseSensitive ? mb_strpos($text, $needle, $offset) : mb_stripos($text, $needle, $offset)) !== false) {
                $ranges[] = ['start' => $pos, 'length' => $needleLen];
                $offset = $pos + max(1, $needleLen);
            }
        }
        foreach ($regexes as $node2) {
            $pattern = $this->buildRegex($node2);
            if ($pattern && preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$matchText, $bytePos]) {
                    if ($matchText !== '' && $bytePos >= 0) {
                        // Convert byte offset → char offset for the UI.
                        $ranges[] = [
                            'start' => mb_strlen(substr($text, 0, $bytePos)),
                            'length' => mb_strlen($matchText),
                        ];
                    }
                }
            }
        }

        return $this->mergeRanges($ranges);
    }

    private function collectNeedles(Node $node, array &$needles, array &$regexes): void
    {
        switch ($node->type) {
            case 'term':
            case 'phrase':
                $needles[] = $node->prop('value');
                break;
            case 'regex':
                $regexes[] = $node;
                break;
            case 'and':
            case 'or':
                foreach ($node->children as $c) {
                    $this->collectNeedles($c, $needles, $regexes);
                }
                break;
            // skip not/level/time/field — not highlighted
        }
    }

    private function mergeRanges(array $ranges): array
    {
        if (empty($ranges)) {
            return [];
        }
        usort($ranges, fn ($a, $b) => $a['start'] <=> $b['start']);
        $merged = [array_shift($ranges)];
        foreach ($ranges as $r) {
            $last = &$merged[count($merged) - 1];
            if ($r['start'] <= $last['start'] + $last['length']) {
                $end = max($last['start'] + $last['length'], $r['start'] + $r['length']);
                $last['length'] = $end - $last['start'];
            } else {
                $merged[] = $r;
            }
            unset($last);
        }

        return $merged;
    }
}
