<?php

namespace LogLens\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use LogLens\Tests\TestCase;

class SecurityTest extends TestCase
{
    public function test_production_default_deny(): void
    {
        $this->fixtures->laravel('laravel.log', 5);
        // Simulate production: the default gate denies outside local/testing.
        $this->app['env'] = 'production';
        app()->detectEnvironment(fn () => 'production');
        Gate::define('viewLogLens', fn ($user = null) => \LogLens\Security\Authorizer::defaultPolicy('viewLogLens', $user));

        $res = $this->getJson('loglens/api/files');
        $res->assertStatus(403);
        $res->assertJsonStructure(['error' => ['code', 'message']]);
    }

    public function test_traversal_returns_404(): void
    {
        $this->fixtures->laravel('laravel.log', 5);
        // A forged id that doesn't resolve to a discovered file.
        $res = $this->getJson('loglens/api/files/' . str_repeat('a', 16) . '/open');
        $res->assertStatus(404);
    }

    public function test_redaction_applied_in_api(): void
    {
        $secret = 'Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.SflKxwRJSMeKKF2QT4fwpMeJf36';
        file_put_contents(
            $this->logDir . '/secrets.log',
            "[2026-06-13 10:00:00] production.INFO: auth header {$secret}\n"
        );
        $id = $this->fileId('secrets.log');
        $this->getJson("loglens/api/files/{$id}/open");

        $entries = $this->getJson("loglens/api/files/{$id}/entries?limit=10")->json('entries');
        $body = json_encode($entries);
        $this->assertStringContainsString('[redacted]', $body);
        $this->assertStringNotContainsString('eyJzdWIi', $body);
    }

    public function test_read_only_blocks_delete(): void
    {
        config()->set('loglens.read_only', true);
        $this->fixtures->laravel('laravel.log', 5);
        $id = $this->fileId('laravel.log');

        $res = $this->deleteJson("loglens/api/files/{$id}");
        $res->assertStatus(403);
        $this->assertFileExists($this->logDir . '/laravel.log');
    }

    public function test_mail_html_is_sanitized(): void
    {
        $mime = "[2026-06-13 10:00:00] local.DEBUG: Mail\nMIME-Version: 1.0\n"
            . "From: a@b.test\nTo: c@d.test\nSubject: Hi\nContent-Type: text/html\n\n"
            . "<p>Hello</p><script>alert(1)</script><a href=\"javascript:alert(2)\" onclick=\"x()\">click</a>\n";
        file_put_contents($this->logDir . '/mail.log', $mime);
        $id = $this->fileId('mail.log');
        $this->getJson("loglens/api/files/{$id}/open");

        $entries = $this->getJson("loglens/api/files/{$id}/entries?limit=10")->json('entries');
        $res = $this->getJson("loglens/api/files/{$id}/mail/" . $entries[0]['seq']);
        $res->assertOk();
        $html = (string) $res->json('html');
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('Hello', $html);
    }

    public function test_xss_payload_renders_inert(): void
    {
        file_put_contents(
            $this->logDir . '/xss.log',
            "[2026-06-13 10:00:00] production.INFO: <script>alert(1)</script>\n"
        );
        $id = $this->fileId('xss.log');
        $this->getJson("loglens/api/files/{$id}/open");

        $entries = $this->getJson("loglens/api/files/{$id}/entries?limit=10")->json('entries');
        $tokens = json_encode($entries[0]['tokens']);
        $this->assertStringNotContainsString('<script>', $tokens);
        $this->assertStringContainsString('&lt;script&gt;', $tokens);
    }
}
