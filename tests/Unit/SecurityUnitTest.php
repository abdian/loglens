<?php

namespace LogLens\Tests\Unit;

use LogLens\Security\Redactor;
use LogLens\Security\SafeRenderer;
use LogLens\Support\PathGuard;
use PHPUnit\Framework\TestCase;

class SecurityUnitTest extends TestCase
{
    public function test_redacts_jwt_and_bearer(): void
    {
        $r = new Redactor(['enabled' => true]);
        $out = $r->redact('Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVC9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.abc123def456');
        $this->assertStringContainsString('[redacted]', $out);
        $this->assertStringNotContainsString('eyJzdWIi', $out);
    }

    public function test_redacts_aws_and_stripe_keys(): void
    {
        $r = new Redactor(['enabled' => true]);
        $this->assertStringContainsString('[redacted]', $r->redact('key=AKIAIOSFODNN7EXAMPLE'));
        $this->assertStringContainsString('[redacted]', $r->redact('sk_live_abcdefghijklmnop1234'));
    }

    public function test_redacts_password_keys_in_array(): void
    {
        $r = new Redactor(['enabled' => true]);
        $out = $r->redactArray(['user' => 'a', 'password' => 'secret', 'nested' => ['api_key' => 'xyz']]);
        $this->assertSame('[redacted]', $out['password']);
        $this->assertSame('[redacted]', $out['nested']['api_key']);
        $this->assertSame('a', $out['user']);
    }

    public function test_redaction_opt_out(): void
    {
        $r = new Redactor(['enabled' => false]);
        $this->assertStringContainsString('eyJ', $r->redact('Bearer eyJhbGciOiJI.eyJzdWIi.abc'));
    }

    public function test_escapes_html(): void
    {
        $renderer = new SafeRenderer();
        $tokens = $renderer->tokenize('<script>alert(1)</script>');
        $rendered = implode('', array_column($tokens, 'text'));
        $this->assertStringNotContainsString('<script>', $rendered);
        $this->assertStringContainsString('&lt;script&gt;', $rendered);
    }

    public function test_strips_ansi_osc_hyperlinks_keeps_sgr(): void
    {
        $renderer = new SafeRenderer();
        $input = "\x1b]8;;https://evil.test\x07click\x1b]8;;\x07 \x1b[31mred\x1b[0m";
        $tokens = $renderer->tokenize($input);
        $text = implode('', array_column($tokens, 'text'));
        $this->assertStringNotContainsString('evil.test', $text);
        // SGR color preserved as a class
        $hasRed = false;
        foreach ($tokens as $t) {
            if (in_array('ansi-fg-red', $t['classes'], true)) {
                $hasRed = true;
            }
        }
        $this->assertTrue($hasRed);
    }

    public function test_path_guard_rejects_traversal(): void
    {
        $root = sys_get_temp_dir() . '/loglens_guard_' . uniqid();
        mkdir($root);
        file_put_contents($root . '/a.log', 'x');
        $guard = new PathGuard([$root]);

        $this->assertNotNull($guard->resolve($root . '/a.log'));
        $this->assertNull($guard->resolve($root . '/../../../etc/passwd'));
        $this->assertNull($guard->resolve('/etc/passwd'));

        @unlink($root . '/a.log');
        @rmdir($root);
    }
}
