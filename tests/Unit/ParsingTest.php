<?php

namespace LogLens\Tests\Unit;

use LogLens\Indexing\Level;
use LogLens\Parsing\ParserManager;
use PHPUnit\Framework\TestCase;

class ParsingTest extends TestCase
{
    private function pm(): ParserManager
    {
        return new ParserManager(['vendor_markers' => ['/vendor/']]);
    }

    public function test_parses_laravel_exception_with_stacktrace(): void
    {
        $raw = "[2026-06-13 14:30:00] production.ERROR: Something failed {\"exception\":\"[object] (RuntimeException(code: 0): Something failed at /app/app/Service.php:42)\n[stacktrace]\n#0 /app/vendor/laravel/framework/F.php(10): App\\Service->run()\n#1 /app/app/Http/Controllers/X.php(20): App\\Service->go()\n#2 {main}\n\"}";
        $entry = $this->pm()->get('laravel')->parse($raw);

        $this->assertSame(Level::ERROR, $entry->level);
        $this->assertSame('RuntimeException', $entry->exceptionClass);
        $this->assertSame('/app/app/Service.php', $entry->throwFile);
        $this->assertSame(42, $entry->throwLine);
        // top app frame is the first non-vendor frame
        $this->assertSame('/app/app/Http/Controllers/X.php:20', $entry->appFrame);
        $this->assertSame('Something failed', $entry->message);
    }

    public function test_splits_dual_json_context_tails(): void
    {
        $raw = '[2026-06-13 14:30:00] local.INFO: User authenticated. {"auth_id":27} {"trace_id":"abc","url":"/x"}';
        $entry = $this->pm()->get('laravel')->parse($raw);

        $this->assertSame('User authenticated.', $entry->message);
        $this->assertSame(['auth_id' => 27], $entry->context);
        $this->assertSame(['trace_id' => 'abc', 'url' => '/x'], $entry->extra);
    }

    public function test_continuation_lines_attach_to_entry(): void
    {
        $pm = $this->pm();
        $laravel = $pm->get('laravel');
        $this->assertTrue($laravel->isEntryStart('[2026-06-13 14:30:00] local.INFO: hi'));
        $this->assertFalse($laravel->isEntryStart('    at SomeClass->method()'));
    }

    public function test_detects_and_parses_ndjson(): void
    {
        $line = '{"message":"hello","level":400,"level_name":"ERROR","channel":"app","datetime":"2026-06-13T14:30:00.000000+00:00","context":{"a":1}}';
        $parser = $this->pm()->detect([$line]);
        $this->assertSame('json', $parser->id());

        $entry = $parser->parse($line);
        $this->assertSame(Level::ERROR, $entry->level);
        $this->assertSame('hello', $entry->message);
        $this->assertSame(['a' => 1], $entry->context);
        $this->assertNotNull($entry->timestamp);
    }

    public function test_detects_nginx_error(): void
    {
        $line = '2026/06/13 14:30:00 [error] 1234#0: *1 open() failed';
        $parser = $this->pm()->detect([$line]);
        $this->assertSame('nginx_error', $parser->id());
        $this->assertSame(Level::ERROR, $parser->parse($line)->level);
    }

    public function test_utf8_resilience_does_not_throw(): void
    {
        $raw = "[2026-06-13 14:30:00] local.INFO: bad \xFF\xFE bytes 🚀 پیام";
        $entry = $this->pm()->get('laravel')->parse($raw);
        $json = json_encode($entry->message, JSON_INVALID_UTF8_SUBSTITUTE);
        $this->assertIsString($json);
        $this->assertNotFalse($json);
    }

    public function test_exception_message_containing_at_keeps_throw_site(): void
    {
        // Regression: greedy message must not stop at a literal " at " inside it.
        $raw = "[2026-06-13 14:30:00] production.ERROR: Failed to connect at startup {\"exception\":\"[object] (RuntimeException(code: 0): Failed to connect at startup at /app/app/Db.php:88)\n[stacktrace]\n#0 /app/app/Db.php(88): App\\Db->open()\n#1 {main}\n\"}";
        $entry = $this->pm()->get('laravel')->parse($raw);

        $this->assertSame('RuntimeException', $entry->exceptionClass);
        $this->assertSame('/app/app/Db.php', $entry->throwFile);
        $this->assertSame(88, $entry->throwLine);
        $this->assertSame('Failed to connect at startup', $entry->message);
    }

    public function test_utf8_bom_at_file_start_does_not_break_first_entry(): void
    {
        // Some Windows tools prepend a UTF-8 BOM; the first entry must still
        // parse correctly (regression from a real install test).
        $raw = "\xEF\xBB\xBF[2026-06-13 10:00:00] production.ERROR: payment failed";
        $parser = $this->pm()->get('laravel');
        $this->assertTrue($parser->isEntryStart($raw));
        $entry = $parser->parse($raw);
        $this->assertSame(Level::ERROR, $entry->level);
        $this->assertSame('payment failed', $entry->message);
    }

    public function test_apache_error_timestamp_parses(): void
    {
        $line = '[Sat Jun 13 14:30:00.123456 2026] [core:error] [pid 1234] message here';
        $parser = $this->pm()->detect([$line]);
        $entry = $parser->parse($line);
        $this->assertNotNull($entry->timestamp);
        $this->assertSame('2026-06-13', gmdate('Y-m-d', $entry->timestamp));
    }

    public function test_http_access_status_maps_to_level(): void
    {
        $line = '127.0.0.1 - - [13/Jun/2026:14:30:00 +0000] "GET /x HTTP/1.1" 500 12 "-" "curl"';
        $parser = $this->pm()->detect([$line]);
        $this->assertSame('http_access', $parser->id());
        $this->assertSame(Level::ERROR, $parser->parse($line)->level);
    }
}
