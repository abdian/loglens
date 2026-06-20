<?php

namespace LogLens\Tests\Unit;

use LogLens\Search\Node;
use LogLens\Search\ParseException;
use LogLens\Search\QueryParser;
use PHPUnit\Framework\TestCase;

class QueryParserTest extends TestCase
{
    public function test_unknown_level_is_rejected(): void
    {
        $this->expectException(ParseException::class);
        (new QueryParser())->parse('level:eror');
    }

    public function test_known_and_numeric_levels_are_accepted(): void
    {
        foreach (['level:error', 'level:warn', 'level:>=warning', 'level:400', 'level:unknown'] as $q) {
            $this->assertNotNull((new QueryParser())->parse($q), $q);
        }
    }

    public function test_backslash_in_quoted_phrase_is_preserved(): void
    {
        // Only \" and \\ are escapes; other backslashes (Windows paths) stay literal.
        $ast = (new QueryParser())->parse('"C:\\Users\\app"');
        $this->assertSame('phrase', $ast->type);
        $this->assertSame('C:\\Users\\app', $ast->prop('value'));
    }

    public function test_compound_query(): void
    {
        $now = gmmktime(16, 0, 0, 6, 13, 2026);
        $ast = (new QueryParser($now))->parse('level:error after:-1h "payment failed" -channel:horizon');

        $this->assertSame('and', $ast->type);
        $this->assertCount(4, $ast->children);

        $types = array_map(fn (Node $n) => $n->type, $ast->children);
        $this->assertContains('level', $types);
        $this->assertContains('time', $types);
        $this->assertContains('phrase', $types);
        $this->assertContains('not', $types);
    }

    public function test_relative_time_resolution(): void
    {
        $now = gmmktime(16, 0, 0, 6, 13, 2026);
        $ast = (new QueryParser($now))->parse('after:-1h');
        $this->assertSame('time', $ast->type);
        $this->assertSame($now - 3600, $ast->prop('value'));
    }

    public function test_invalid_query_reports_position(): void
    {
        $this->expectException(ParseException::class);
        try {
            (new QueryParser())->parse('(unclosed level:error');
        } catch (ParseException $e) {
            $this->assertGreaterThanOrEqual(0, $e->position);
            throw $e;
        }
    }

    public function test_special_chars_treated_literally(): void
    {
        $ast = (new QueryParser())->parse('[error] (code 1.5)');
        // No regex node — all bare terms ANDed.
        $this->assertSame('and', $ast->type);
        foreach ($ast->children as $child) {
            $this->assertNotSame('regex', $child->type);
        }
    }

    public function test_regex_opt_in(): void
    {
        $ast = (new QueryParser())->parse('/teapot|kettle/i');
        $this->assertSame('regex', $ast->type);
        $this->assertSame('teapot|kettle', $ast->prop('value'));
        $this->assertSame('i', $ast->prop('flags'));
    }

    public function test_or_with_parentheses(): void
    {
        $ast = (new QueryParser())->parse('(foo bar) OR baz');
        $this->assertSame('or', $ast->type);
        $this->assertCount(2, $ast->children);
    }

    public function test_empty_query_matches_all(): void
    {
        $this->assertSame('all', (new QueryParser())->parse('')->type);
    }
}
