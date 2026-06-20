<?php

namespace LogLens\Security;

/**
 * Display-time secret redaction. ON by default; the
 * curated pattern set is applied across UI, API, copy and export paths. Opt-out
 * only — never opt-in. Patterns replace the secret portion with a marker so the
 * surrounding context stays readable.
 */
class Redactor
{
    private bool $enabled;

    private string $marker;

    /** @var array<int,array{0:string,1:string}> */
    private array $patterns;

    public function __construct(array $config = [])
    {
        $this->enabled = (bool) ($config['enabled'] ?? true);
        $this->marker = $config['marker'] ?? '[redacted]';
        $this->patterns = $this->buildPatterns($config);
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function redact(string $text): string
    {
        if (! $this->enabled || $text === '') {
            return $text;
        }

        foreach ($this->patterns as [$pattern, $replacement]) {
            $out = @preg_replace($pattern, $replacement, $text);
            if ($out !== null) {
                $text = $out;
            }
        }

        return $text;
    }

    /** Redact recursively through a decoded context/extra array. */
    public function redactArray(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $out[$key] = $this->marker;
                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->redactArray($value);
            } elseif (is_string($value)) {
                $out[$key] = $this->redact($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function isSensitiveKey(string $key): bool
    {
        return (bool) preg_match('/^(?:password|passwd|secret|token|api[_-]?key|authorization|access[_-]?token|refresh[_-]?token|client[_-]?secret|private[_-]?key)$/i', $key);
    }

    private function buildPatterns(array $config): array
    {
        $m = $this->marker;
        $patterns = [
            // Authorization: Bearer <jwt/token>
            ['/(Authorization:\s*Bearer\s+)[A-Za-z0-9._\-]+/i', '$1' . $m],
            ['/(Bearer\s+)[A-Za-z0-9._\-]{12,}/', '$1' . $m],
            // Raw JWT (three base64url segments)
            ['/\beyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\b/', $m],
            // Laravel APP_KEY
            ['/base64:[A-Za-z0-9+\/]{40,}=*/', $m],
            // AWS access key id + secret
            ['/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/', $m],
            ['/\baws_secret_access_key\s*[=:]\s*\S+/i', 'aws_secret_access_key=' . $m],
            // Stripe keys
            ['/\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{16,}\b/', $m],
            // GitHub / generic provider tokens
            ['/\bgh[pousr]_[A-Za-z0-9]{30,}\b/', $m],
            // password=... / "password":"..."
            ['/(password"?\s*[:=]\s*"?)[^"\s,&}]+/i', '$1' . $m],
            // Authorization basic
            ['/(Authorization:\s*Basic\s+)[A-Za-z0-9+\/=]+/i', '$1' . $m],
        ];

        if (! empty($config['cards'])) {
            // 13–16 digit card numbers (loose; display only)
            $patterns[] = ['/\b(?:\d[ -]?){13,16}\b/', $m];
        }
        if (! empty($config['emails'])) {
            $patterns[] = ['/[\w.+-]+@[\w-]+\.[\w.-]+/', $m];
        }

        foreach ((array) ($config['patterns'] ?? []) as $userPattern) {
            $patterns[] = [$userPattern, $m];
        }

        return $patterns;
    }
}
