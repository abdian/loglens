<?php

namespace LogLens\Tests\Support;

/**
 * Generates realistic log fixtures for tests: Laravel format with
 * multi-line traces, NDJSON, gz, malformed UTF-8, and emoji/Arabic/Persian
 * content.
 */
class FixtureGenerator
{
    public function __construct(private string $dir)
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function laravel(string $name = 'laravel.log', int $entries = 200): string
    {
        $lines = [];
        $base = gmmktime(0, 0, 0, 6, 13, 2026);
        for ($i = 0; $i < $entries; $i++) {
            $ts = gmdate('Y-m-d H:i:s', $base + $i * 60);
            $level = $i % 25 === 0 ? 'ERROR' : ($i % 10 === 0 ? 'WARNING' : 'INFO');
            $lines[] = "[$ts] production.$level: Processing order $i for user " . ($i % 7)
                . ($i % 50 === 0 ? ' {"order_id":' . $i . ',"amount":42}' : '');
        }
        // Multi-line exception
        $lines[] = '[2026-06-13 12:00:00] production.ERROR: Boom {"exception":"[object] (RuntimeException(code: 0): Boom at /app/app/Service.php:42)';
        $lines[] = '[stacktrace]';
        $lines[] = '#0 /app/vendor/laravel/framework/src/Foundation/Application.php(1): App\\Service->run()';
        $lines[] = '#1 /app/app/Http/Controllers/OrderController.php(20): App\\Service->run()';
        $lines[] = '#2 {main}';
        $lines[] = '"}';

        return $this->write($name, implode("\n", $lines) . "\n");
    }

    public function json(string $name = 'structured.log', int $entries = 50): string
    {
        $lines = [];
        $base = gmmktime(0, 0, 0, 6, 13, 2026);
        for ($i = 0; $i < $entries; $i++) {
            $lines[] = json_encode([
                'message' => "request handled $i",
                'context' => ['user_id' => $i % 5],
                'level' => $i % 10 === 0 ? 400 : 200,
                'level_name' => $i % 10 === 0 ? 'ERROR' : 'INFO',
                'channel' => 'app',
                'datetime' => gmdate('Y-m-d\TH:i:s.000000\Z', $base + $i * 30),
                'extra' => [],
            ]);
        }

        return $this->write($name, implode("\n", $lines) . "\n");
    }

    public function unicode(string $name = 'unicode.log'): string
    {
        $lines = [
            '[2026-06-13 10:00:00] production.INFO: Café ☕ déjà vu — emoji 🚀🎉',
            '[2026-06-13 10:00:01] production.INFO: پیام فارسی — سفارش پرداخت شد',
            '[2026-06-13 10:00:02] production.INFO: رسالة عربية — تم الدفع',
            "[2026-06-13 10:00:03] production.WARNING: malformed \xFF\xFE bytes here",
        ];

        return $this->write($name, implode("\n", $lines) . "\n");
    }

    public function gz(string $name = 'archive.log.gz', int $entries = 30): string
    {
        $content = $this->laravelContent($entries);
        $path = $this->dir . DIRECTORY_SEPARATOR . $name;
        $gz = gzopen($path, 'wb9');
        gzwrite($gz, $content);
        gzclose($gz);

        return $path;
    }

    public function append(string $name, string $content): void
    {
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . $name, $content, FILE_APPEND);
    }

    private function laravelContent(int $entries): string
    {
        $lines = [];
        $base = gmmktime(0, 0, 0, 6, 1, 2026);
        for ($i = 0; $i < $entries; $i++) {
            $ts = gmdate('Y-m-d H:i:s', $base + $i * 60);
            $lines[] = "[$ts] production.INFO: archived entry $i";
        }

        return implode("\n", $lines) . "\n";
    }

    private function write(string $name, string $content): string
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $content);

        return $path;
    }
}
