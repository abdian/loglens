<?php

namespace LogLens\Tests\Feature;

use LogLens\Sources\LocalFileSource;
use LogLens\Tail\Cursor;
use LogLens\Tail\TailEngine;
use LogLens\Tests\TestCase;

class TailTest extends TestCase
{
    public function test_tail_info_reports_transport(): void
    {
        $this->fixtures->laravel('laravel.log', 5);
        $res = $this->getJson('loglens/api/tail/info');
        $res->assertOk();
        $res->assertJsonStructure(['transport', 'force_polling', 'runtime', 'window_seconds']);
    }

    public function test_poll_returns_only_new_bytes(): void
    {
        $path = $this->fixtures->laravel('laravel.log', 10);
        $id = $this->fileId('laravel.log');

        /** @var TailEngine $engine */
        $engine = $this->app->make(TailEngine::class);
        $cursor = (string) $engine->endCursor($id);

        // Nothing new yet.
        $res = $this->getJson("loglens/api/tail/poll?file={$id}&cursor=" . urlencode($cursor));
        $res->assertOk();
        $this->assertTrue($res->json('idle'));

        // Append two entries.
        file_put_contents($path, "[2026-06-13 20:00:00] production.ERROR: appended one\n[2026-06-13 20:00:01] production.INFO: appended two\n", FILE_APPEND);

        $res2 = $this->getJson("loglens/api/tail/poll?file={$id}&cursor=" . urlencode($cursor));
        $res2->assertOk();
        $this->assertFalse($res2->json('idle'));
        $this->assertCount(2, $res2->json('entries'));
    }

    public function test_tail_engine_detects_truncation(): void
    {
        $path = $this->fixtures->laravel('laravel.log', 20);
        $id = $this->fileId('laravel.log');
        /** @var TailEngine $engine */
        $engine = $this->app->make(TailEngine::class);

        $cursor = $engine->endCursor($id);
        // Truncate the file (external clear).
        file_put_contents($path, "[2026-06-13 21:00:00] production.INFO: fresh start\n");

        $result = $engine->read($id, $cursor);
        $this->assertTrue($result['truncated'] || $result['rotated']);
        $this->assertNotEmpty($result['entries']);
    }

    public function test_filtered_tail(): void
    {
        $path = $this->fixtures->laravel('laravel.log', 5);
        $id = $this->fileId('laravel.log');
        /** @var TailEngine $engine */
        $engine = $this->app->make(TailEngine::class);
        $cursor = $engine->endCursor($id);

        file_put_contents($path, "[2026-06-13 22:00:00] production.INFO: noise\n[2026-06-13 22:00:01] production.ERROR: signal\n", FILE_APPEND);

        $result = $engine->read($id, $cursor, 'level:>=error');
        $this->assertCount(1, $result['entries']);
        $this->assertStringContainsString('signal', $result['entries'][0]['parsed']->message);
    }

    public function test_cursor_roundtrip(): void
    {
        $c = new Cursor('abc123', 42, 1000);
        $parsed = Cursor::parse((string) $c);
        $this->assertSame('abc123', $parsed->pathHash);
        $this->assertSame(42, $parsed->inode);
        $this->assertSame(1000, $parsed->offset);
    }
}
