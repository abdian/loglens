<?php

namespace LogLens\Search;

/**
 * Query AST node. A single discriminated type keeps the
 * dependency-free, PHP-8.0 parser and the three compilers compact.
 *
 * Types:
 *   term    {value, wildcard:bool}            literal substring (implicit AND)
 *   phrase  {value}                           quoted exact phrase
 *   regex   {value, flags}                    /pattern/flags
 *   field   {field, op, value}                channel:/env:/file:/context filters
 *   level   {op, value}                       level:>=warning
 *   time    {field:after|before, value:int}   resolved unix ts
 *   not     {child}
 *   and     {children[]}
 *   or      {children[]}
 *   all                                       matches everything (empty query)
 */
final class Node
{
    public function __construct(
        public string $type,
        public array $props = [],
        /** @var Node[] */
        public array $children = []
    ) {
    }

    public static function term(string $value, bool $wildcard = false): self
    {
        return new self('term', ['value' => $value, 'wildcard' => $wildcard]);
    }

    public static function phrase(string $value): self
    {
        return new self('phrase', ['value' => $value]);
    }

    public static function regex(string $value, string $flags = ''): self
    {
        return new self('regex', ['value' => $value, 'flags' => $flags]);
    }

    public static function field(string $field, string $op, string $value): self
    {
        return new self('field', ['field' => $field, 'op' => $op, 'value' => $value]);
    }

    public static function level(string $op, string $value): self
    {
        return new self('level', ['op' => $op, 'value' => $value]);
    }

    public static function time(string $field, int $value): self
    {
        return new self('time', ['field' => $field, 'value' => $value]);
    }

    public static function not(Node $child): self
    {
        return new self('not', [], [$child]);
    }

    public static function andNode(array $children): self
    {
        return count($children) === 1 ? $children[0] : new self('and', [], $children);
    }

    public static function orNode(array $children): self
    {
        return count($children) === 1 ? $children[0] : new self('or', [], $children);
    }

    public static function all(): self
    {
        return new self('all');
    }

    public function prop(string $key, $default = null)
    {
        return $this->props[$key] ?? $default;
    }
}
