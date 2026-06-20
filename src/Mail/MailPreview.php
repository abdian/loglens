<?php

namespace LogLens\Mail;

use LogLens\Support\Utf8;

/**
 * Extract and render a logged MIME message (`MAIL_MAILER=log`) from a log entry
 *. Returns headers, HTML + plain parts and a list
 * of attachments without writing anything to disk. Best-effort, dependency-free.
 */
final class MailPreview
{
    public static function detect(string $raw): bool
    {
        return (bool) preg_match('/^(?:Message-ID|MIME-Version|Content-Type|Subject|From|To):/mi', $raw)
            && stripos($raw, 'Content-Type:') !== false;
    }

    public static function extract(string $raw): ?array
    {
        $raw = Utf8::sanitize($raw);
        // Trim the Laravel log preamble up to the first MIME header.
        if (preg_match('/(?:Message-ID|MIME-Version|Date|From|To|Subject|Content-Type):/i', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $raw = substr($raw, $m[0][1]);
        }

        $split = preg_split("/\r?\n\r?\n/", $raw, 2);
        if (count($split) < 2) {
            return null;
        }
        [$headerBlock, $body] = $split;

        $headers = self::parseHeaders($headerBlock);
        if (empty($headers)) {
            return null;
        }

        $contentType = $headers['content-type'] ?? '';
        $parts = ['html' => null, 'text' => null, 'attachments' => []];

        if (preg_match('/boundary="?([^";\r\n]+)"?/i', $contentType, $bm)) {
            self::parseMultipart($body, $bm[1], $parts);
        } else {
            if (stripos($contentType, 'text/html') !== false) {
                $parts['html'] = self::decodeBody($body, $headers);
            } else {
                $parts['text'] = self::decodeBody($body, $headers);
            }
        }

        return [
            'headers' => [
                'from' => $headers['from'] ?? null,
                'to' => $headers['to'] ?? null,
                'subject' => $headers['subject'] ?? null,
                'date' => $headers['date'] ?? null,
            ],
            'html' => $parts['html'],
            'text' => $parts['text'],
            'attachments' => $parts['attachments'],
        ];
    }

    /**
     * Best-effort sanitizer for the logged HTML part. Log
     * content is attacker-controlled, so we strip script/style/frame/embed
     * elements, inline event handlers and dangerous URL schemes before the body
     * is ever returned. The SPA additionally renders this inside a sandboxed
     * iframe — defence in depth, never trust one layer.
     */
    public static function sanitizeHtml(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }
        // Drop elements that can execute or exfiltrate, with their content.
        $html = preg_replace('#<\s*(script|style|iframe|object|embed|link|meta|base|form|svg)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html) ?? $html;
        // Drop self-closing / unclosed dangerous tags too.
        $html = preg_replace('#<\s*(script|style|iframe|object|embed|link|meta|base|form|svg)\b[^>]*/?\s*>#i', '', $html) ?? $html;
        // Strip inline event handlers: on*="..." / on*=\'...\' / on*=unquoted.
        $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? $html;
        // Neutralize dangerous URL schemes in href/src.
        $html = preg_replace('#(href|src)\s*=\s*("|\')?\s*(javascript|vbscript|data(?!:image/)):[^"\'>\s]*#i', '$1="#"', $html) ?? $html;

        return $html;
    }

    private static function parseHeaders(string $block): array
    {
        $headers = [];
        $current = null;
        foreach (preg_split('/\r?\n/', $block) as $line) {
            if (preg_match('/^([\w-]+):\s*(.*)$/', $line, $m)) {
                $current = strtolower($m[1]);
                $headers[$current] = $m[2];
            } elseif ($current !== null && preg_match('/^\s+(.*)$/', $line, $m)) {
                $headers[$current] .= ' ' . trim($m[1]);
            }
        }

        return $headers;
    }

    private static function parseMultipart(string $body, string $boundary, array &$parts): void
    {
        $chunks = explode('--' . $boundary, $body);
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk, "\r\n");
            if ($chunk === '' || $chunk === '--') {
                continue;
            }
            $split = preg_split("/\r?\n\r?\n/", $chunk, 2);
            if (count($split) < 2) {
                continue;
            }
            [$h, $b] = $split;
            $headers = self::parseHeaders($h);
            $ct = $headers['content-type'] ?? '';
            $disposition = $headers['content-disposition'] ?? '';

            if (stripos($disposition, 'attachment') !== false || preg_match('/name="?([^";\r\n]+)"?/i', $ct, $nm)) {
                $name = 'attachment';
                if (preg_match('/filename="?([^";\r\n]+)"?/i', $disposition, $fn)) {
                    $name = $fn[1];
                } elseif (isset($nm)) {
                    $name = $nm[1];
                }
                $parts['attachments'][] = ['name' => $name, 'type' => trim(explode(';', $ct)[0]), 'size' => strlen($b)];
                continue;
            }

            if (stripos($ct, 'multipart/') !== false && preg_match('/boundary="?([^";\r\n]+)"?/i', $ct, $bm)) {
                self::parseMultipart($b, $bm[1], $parts);
            } elseif (stripos($ct, 'text/html') !== false) {
                $parts['html'] = self::decodeBody($b, $headers);
            } elseif (stripos($ct, 'text/plain') !== false) {
                $parts['text'] = self::decodeBody($b, $headers);
            }
        }
    }

    private static function decodeBody(string $body, array $headers): string
    {
        $encoding = strtolower($headers['content-transfer-encoding'] ?? '');
        $body = trim($body);
        if ($encoding === 'base64') {
            return Utf8::sanitize((string) base64_decode($body, false));
        }
        if ($encoding === 'quoted-printable') {
            return Utf8::sanitize(quoted_printable_decode($body));
        }

        return Utf8::sanitize($body);
    }
}
