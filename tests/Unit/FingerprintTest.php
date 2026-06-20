<?php

namespace LogLens\Tests\Unit;

use LogLens\Fingerprint\FingerprintEngine;
use LogLens\Fingerprint\MessageTemplate;
use LogLens\Parsing\Parsers\LaravelLogParser;
use PHPUnit\Framework\TestCase;

class FingerprintTest extends TestCase
{
    public function test_message_template_masks_ids(): void
    {
        $a = MessageTemplate::normalize('No query results for model [App\\Order] 4211');
        $b = MessageTemplate::normalize('No query results for model [App\\Order] 9377');
        $this->assertSame($a, $b);
        $this->assertStringContainsString('<num>', $a);
    }

    public function test_message_template_masks_large_numbers(): void
    {
        // 8+ digit values (memory sizes, large IDs, epoch-millis) must also be
        // masked, or one error fragments into many fingerprints.
        $a = MessageTemplate::normalize('Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)');
        $b = MessageTemplate::normalize('Allowed memory size of 268435456 bytes exhausted (tried to allocate 81920 bytes)');
        $this->assertSame($a, $b);
        $this->assertStringNotContainsString('134217728', $a);
    }

    public function test_constant_message_skips_normalization(): void
    {
        $msg = 'Cache cleared successfully';
        $this->assertSame($msg, MessageTemplate::normalize($msg));
    }

    public function test_same_exception_same_fingerprint(): void
    {
        $parser = new LaravelLogParser(['/vendor/']);
        $engine = new FingerprintEngine([]);

        $raw1 = "[2026-06-13 10:00:00] production.ERROR: Boom {\"exception\":\"[object] (RuntimeException(code: 0): Boom at /app/app/Svc.php:10)\n[stacktrace]\n#0 /app/app/Svc.php(10): App\\Svc->go()\n#1 {main}\n\"}";
        // Same exception thrown on a different day → identical fingerprint.
        $raw2 = "[2026-06-14 11:00:00] production.ERROR: Boom {\"exception\":\"[object] (RuntimeException(code: 0): Boom at /app/app/Svc.php:10)\n[stacktrace]\n#0 /app/app/Svc.php(10): App\\Svc->go()\n#1 {main}\n\"}";

        $fp1 = $engine->compute($parser->parse($raw1));
        $fp2 = $engine->compute($parser->parse($raw2));

        $this->assertSame($fp1['app'], $fp2['app']);
        $this->assertSame(FingerprintEngine::KIND_EXCEPTION, $fp1['kind']);
    }

    public function test_deploy_stability_drops_line_numbers(): void
    {
        $parser = new LaravelLogParser(['/vendor/']);
        $stable = new FingerprintEngine(['deploy_stability' => true]);

        $rawA = "[2026-06-13 10:00:00] production.ERROR: X {\"exception\":\"[object] (RuntimeException(code: 0): X at /app/app/Svc.php:10)\n[stacktrace]\n#0 /app/app/Svc.php(10): App\\Svc->go()\n#1 {main}\n\"}";
        $rawB = "[2026-06-13 10:00:00] production.ERROR: X {\"exception\":\"[object] (RuntimeException(code: 0): X at /app/app/Svc.php:25)\n[stacktrace]\n#0 /app/app/Svc.php(25): App\\Svc->go()\n#1 {main}\n\"}";

        $this->assertSame(
            $stable->compute($parser->parse($rawA))['app'],
            $stable->compute($parser->parse($rawB))['app']
        );
    }

    public function test_query_exception_groups_by_normalized_sql(): void
    {
        $engine = new FingerprintEngine(['rules' => ['Illuminate\\Database\\QueryException' => 'class_sql']]);
        $parser = new LaravelLogParser(['/vendor/']);

        $a = "[2026-06-13 10:00:00] production.ERROR: SQLSTATE {\"exception\":\"[object] (Illuminate\\\\Database\\\\QueryException(code: 0): SQLSTATE error (SQL: select * from users where id = 1) at /app/app/Db.php:5)\n[stacktrace]\n#0 {main}\n\"}";
        $b = "[2026-06-13 10:00:00] production.ERROR: SQLSTATE {\"exception\":\"[object] (Illuminate\\\\Database\\\\QueryException(code: 0): SQLSTATE error (SQL: select * from users where id = 99) at /app/app/Db.php:5)\n[stacktrace]\n#0 {main}\n\"}";

        $this->assertSame(
            $engine->compute($parser->parse($a))['app'],
            $engine->compute($parser->parse($b))['app']
        );
    }
}
