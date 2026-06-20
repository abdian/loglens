<?php

namespace LogLens\Search;

/**
 * Compile a query AST into a superset-safe FTS5 MATCH expression. "Superset-safe" means the MATCH never excludes a true result —
 * any term it cannot express safely becomes "unconstrained" (null), and the
 * AstEvaluator re-confirms every candidate, so correctness never depends on the
 * MATCH being exact, only on it not over-narrowing.
 *
 * Trigram tokenizer: substrings of length >= 3 are MATCH-safe. Shorter terms,
 * regex, and field/level/time predicates are left to the evaluator.
 */
class FtsCompiler
{
    public function __construct(private bool $trigram)
    {
    }

    /** Returns an FTS5 MATCH string, or null when the index can't safely narrow. */
    public function compile(Node $node): ?string
    {
        // Without trigram we don't trust FTS to match arbitrary substrings.
        if (! $this->trigram) {
            return null;
        }

        return $this->walk($node);
    }

    private function walk(Node $node): ?string
    {
        switch ($node->type) {
            case 'term':
                $v = $node->prop('value');
                // Trigram tokenizes into 3-CHARACTER sequences; gate on char
                // length (mb), not bytes, or multibyte terms get excluded.
                return mb_strlen($v) >= 3 ? $this->quote($v) : null;
            case 'phrase':
                $v = trim($node->prop('value'));

                return mb_strlen($v) >= 3 ? $this->quote($v) : null;
            case 'and':
                $parts = [];
                foreach ($node->children as $c) {
                    $p = $this->walk($c);
                    if ($p !== null) {
                        $parts[] = $p;
                    }
                }

                return empty($parts) ? null : '(' . implode(' AND ', $parts) . ')';
            case 'or':
                $parts = [];
                foreach ($node->children as $c) {
                    $p = $this->walk($c);
                    if ($p === null) {
                        // Any unconstrained branch makes the whole OR unsafe.
                        return null;
                    }
                    $parts[] = $p;
                }

                return empty($parts) ? null : '(' . implode(' OR ', $parts) . ')';
            // not / level / time / field / regex / all → unconstrained
            default:
                return null;
        }
    }

    private function quote(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }
}
