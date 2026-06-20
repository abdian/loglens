<?php

namespace LogLens\Search;

use LogLens\Indexing\Level;
use LogLens\Support\Timestamp;

/**
 * Dependency-free tokenizer + recursive-descent parser → AST. Runs on PHP 8.0. Grammar:
 *
 *   expr    := or
 *   or      := and (OR and)*
 *   and     := unary (unary)*          // implicit AND between adjacent terms
 *   unary   := (NOT | '-') unary | primary
 *   primary := '(' expr ')' | phrase | regex | field | term
 */
class QueryParser
{
    /** @var array<int,array{type:string,value:string,pos:int}> */
    private array $tokens = [];

    private int $pos = 0;

    private int $now;

    public function __construct(?int $now = null)
    {
        $this->now = $now ?? time();
    }

    public function parse(string $query): Node
    {
        $query = trim($query);
        if ($query === '') {
            return Node::all();
        }

        $this->tokens = $this->tokenize($query);
        $this->pos = 0;

        $node = $this->parseOr();

        if ($this->current() !== null) {
            throw new ParseException('Unexpected token "' . $this->current()['value'] . '"', $this->current()['pos']);
        }

        return $node;
    }

    // ---- tokenizer ---------------------------------------------------------

    private function tokenize(string $q): array
    {
        $tokens = [];
        $len = strlen($q);
        $i = 0;
        while ($i < $len) {
            $ch = $q[$i];
            if (ctype_space($ch)) {
                $i++;
                continue;
            }
            if ($ch === '(') {
                $tokens[] = ['type' => 'lparen', 'value' => '(', 'pos' => $i++];
                continue;
            }
            if ($ch === ')') {
                $tokens[] = ['type' => 'rparen', 'value' => ')', 'pos' => $i++];
                continue;
            }
            if ($ch === '"') {
                $start = $i;
                [$value, $i] = $this->readQuoted($q, $i, $len);
                $tokens[] = ['type' => 'phrase', 'value' => $value, 'pos' => $start];
                continue;
            }
            if ($ch === '/') {
                $regex = $this->readRegex($q, $i, $len);
                if ($regex !== null) {
                    [$value, $flags, $next] = $regex;
                    $tokens[] = ['type' => 'regex', 'value' => $value, 'flags' => $flags, 'pos' => $i];
                    $i = $next;
                    continue;
                }
            }
            // bare word (may contain field:value, comparisons, quotes for values)
            $start = $i;
            [$word, $i] = $this->readWord($q, $i, $len);
            $upper = strtoupper($word);
            if ($upper === 'OR' || $upper === 'AND' || $upper === 'NOT') {
                $tokens[] = ['type' => strtolower($upper), 'value' => $word, 'pos' => $start];
            } else {
                $tokens[] = ['type' => 'word', 'value' => $word, 'pos' => $start];
            }
        }

        return $tokens;
    }

    private function readQuoted(string $q, int $i, int $len): array
    {
        $i++; // opening quote
        $value = '';
        while ($i < $len && $q[$i] !== '"') {
            // Only \" and \\ are escapes; every other backslash is literal so
            // Windows paths and regex-ish phrases ("C:\Users", "\d+") survive.
            if ($q[$i] === '\\' && $i + 1 < $len && ($q[$i + 1] === '"' || $q[$i + 1] === '\\')) {
                $i++;
            }
            $value .= $q[$i++];
        }
        if ($i >= $len) {
            throw new ParseException('Unterminated quoted phrase', $len);
        }
        $i++; // closing quote

        return [$value, $i];
    }

    private function readRegex(string $q, int $i, int $len): ?array
    {
        $j = $i + 1;
        $value = '';
        while ($j < $len && $q[$j] !== '/') {
            if ($q[$j] === '\\' && $j + 1 < $len) {
                $value .= $q[$j] . $q[$j + 1];
                $j += 2;
                continue;
            }
            $value .= $q[$j++];
        }
        if ($j >= $len) {
            return null; // not a regex, treat '/' as a normal char
        }
        $j++; // closing slash
        $flags = '';
        while ($j < $len && ctype_alpha($q[$j])) {
            $flags .= $q[$j++];
        }

        return [$value, $flags, $j];
    }

    private function readWord(string $q, int $i, int $len): array
    {
        $word = '';
        while ($i < $len) {
            $ch = $q[$i];
            if (ctype_space($ch) || $ch === '(' || $ch === ')') {
                break;
            }
            if ($ch === '"') {
                // allow quoted field values: field:"a b"
                [$inner, $i] = $this->readQuoted($q, $i, $len);
                $word .= '"' . $inner . '"';
                continue;
            }
            $word .= $ch;
            $i++;
        }

        return [$word, $i];
    }

    // ---- parser ------------------------------------------------------------

    private function parseOr(): Node
    {
        $nodes = [$this->parseAnd()];
        while ($this->current() && $this->current()['type'] === 'or') {
            $this->advance();
            $nodes[] = $this->parseAnd();
        }

        return Node::orNode($nodes);
    }

    private function parseAnd(): Node
    {
        $nodes = [];
        while (($tok = $this->current()) !== null && $tok['type'] !== 'or' && $tok['type'] !== 'rparen') {
            if ($tok['type'] === 'and') {
                $this->advance();
                continue;
            }
            $nodes[] = $this->parseUnary();
        }
        if (empty($nodes)) {
            throw new ParseException('Expected a term', $this->current()['pos'] ?? 0);
        }

        return Node::andNode($nodes);
    }

    private function parseUnary(): Node
    {
        $tok = $this->current();
        if ($tok && $tok['type'] === 'not') {
            $this->advance();

            return Node::not($this->parseUnary());
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): Node
    {
        $tok = $this->current();
        if ($tok === null) {
            throw new ParseException('Unexpected end of query', 0);
        }

        if ($tok['type'] === 'lparen') {
            $this->advance();
            $node = $this->parseOr();
            $close = $this->current();
            if (! $close || $close['type'] !== 'rparen') {
                throw new ParseException('Missing closing parenthesis', $tok['pos']);
            }
            $this->advance();

            return $node;
        }

        if ($tok['type'] === 'phrase') {
            $this->advance();

            return Node::phrase($tok['value']);
        }

        if ($tok['type'] === 'regex') {
            $this->advance();

            return Node::regex($tok['value'], $tok['flags'] ?? '');
        }

        if ($tok['type'] === 'word') {
            $this->advance();

            return $this->interpretWord($tok['value'], $tok['pos']);
        }

        throw new ParseException('Unexpected token "' . $tok['value'] . '"', $tok['pos']);
    }

    private function interpretWord(string $word, int $pos): Node
    {
        // Leading negation.
        if ($word !== '' && $word[0] === '-' && strlen($word) > 1) {
            return Node::not($this->interpretWord(substr($word, 1), $pos + 1));
        }

        // field:value (only split on the first colon; value may contain more).
        $colon = strpos($word, ':');
        if ($colon !== false && $colon > 0) {
            $field = strtolower(substr($word, 0, $colon));
            $value = substr($word, $colon + 1);
            $value = trim($value, '"');

            return $this->interpretField($field, $value, $pos);
        }

        // Trailing wildcard.
        if (substr($word, -1) === '*') {
            return Node::term(rtrim($word, '*'), true);
        }

        return Node::term($word);
    }

    private function interpretField(string $field, string $value, int $pos): Node
    {
        switch ($field) {
            case 'level':
                [$op, $val] = $this->splitComparison($value);
                $this->assertKnownLevel($val, $pos);

                return Node::level($op, $val);
            case 'after':
            case 'since':
                return Node::time('after', $this->resolveTime($value, $pos));
            case 'before':
            case 'until':
                return Node::time('before', $this->resolveTime($value, $pos));
            case 'channel':
            case 'env':
            case 'environment':
            case 'file':
            default:
                [$op, $val] = $this->splitComparison($value);

                return Node::field($field, $op, $val);
        }
    }

    /**
     * Reject an unknown level name at parse time (→ a structured 422 with the
     * offending position) instead of silently resolving it to UNKNOWN and
     * returning a confidently-empty result set. Numeric ordinals and the literal
     * "unknown" are accepted.
     */
    private function assertKnownLevel(string $value, int $pos): void
    {
        if ($value === '') {
            throw new ParseException('A log level is required after "level:"', $pos);
        }
        if (ctype_digit($value)) {
            return;
        }
        if (Level::ordinal($value) === Level::UNKNOWN && strtolower($value) !== 'unknown') {
            throw new ParseException('Unknown log level "' . $value . '"', $pos);
        }
    }

    private function splitComparison(string $value): array
    {
        if (preg_match('/^(>=|<=|>|<|=)(.+)$/', $value, $m)) {
            return [$m[1], $m[2]];
        }

        return ['=', $value];
    }

    private function resolveTime(string $value, int $pos): int
    {
        // Relative: -1h, -30m, -2d, -1w, -10s
        if (preg_match('/^-(\d+)([smhdw])$/i', $value, $m)) {
            $n = (int) $m[1];
            $unit = strtolower($m[2]);
            $mult = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800][$unit];

            return $this->now - $n * $mult;
        }
        if ($value === 'now') {
            return $this->now;
        }
        $ts = Timestamp::parse($value);
        if ($ts === null) {
            throw new ParseException('Invalid time value "' . $value . '"', $pos);
        }

        return $ts;
    }

    private function current(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function advance(): void
    {
        $this->pos++;
    }
}
