<?php

namespace LogLens\Tests\Unit;

use LogLens\Http\EntryPresenter;
use LogLens\Http\Middleware\IpAllowlist;
use LogLens\Parsing\ParsedEntry;
use LogLens\Security\Redactor;
use LogLens\Security\SafeRenderer;
use LogLens\Support\FileIdentity;
use LogLens\Support\Timestamp;
use LogLens\Tail\Cursor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression coverage for the review fixes that have clean, Laravel-free APIs.
 */
class RegressionTest extends TestCase
{
    protected function tearDown(): void
    {
        // Never leak a non-UTC source zone into other tests.
        Timestamp::useTimezone(null);
        parent::tearDown();
    }

    public function test_offsetless_timestamp_uses_configured_source_zone(): void
    {
        Timestamp::useTimezone('America/New_York'); // June → EDT (UTC-4)
        // 14:30 local EDT == 18:30 UTC.
        $this->assertSame(gmmktime(18, 30, 0, 6, 13, 2026), Timestamp::parse('2026-06-13 14:30:00'));
    }

    public function test_explicit_offset_and_z_ignore_source_zone(): void
    {
        Timestamp::useTimezone('America/New_York');
        $utc = gmmktime(14, 30, 0, 6, 13, 2026);
        $this->assertSame($utc, Timestamp::parse('2026-06-13T14:30:00Z'));
        $this->assertSame($utc, Timestamp::parse('2026-06-13 14:30:00+00:00'));
    }

    public function test_utc_default_is_unchanged(): void
    {
        Timestamp::useTimezone(null);
        $this->assertSame(gmmktime(14, 30, 0, 6, 13, 2026), Timestamp::parse('2026-06-13 14:30:00'));
    }

    public function test_cursor_roundtrips_with_and_without_head_fingerprint(): void
    {
        $legacy = Cursor::parse('abcd:1a:100');
        $this->assertSame('abcd', $legacy->pathHash);
        $this->assertSame(26, $legacy->inode);
        $this->assertSame(100, $legacy->offset);
        $this->assertNull($legacy->headFp);

        $full = Cursor::parse('abcd:1a:100:deadbeef');
        $this->assertSame('deadbeef', $full->headFp);
        $this->assertSame((string) $full, (string) Cursor::parse((string) $full));
    }

    public function test_rotation_detected_via_head_fingerprint_when_inode_absent(): void
    {
        // Inode-less mount (FAT/SMB): inode is null on both sides, so the head
        // fingerprint is the only rotation signal.
        $cursor = new Cursor('ph', null, 50, 'headA');
        $same = new FileIdentity('ph', null, null, 'headA', 100, 0, '/x');
        $rotated = new FileIdentity('ph', null, null, 'headB', 100, 0, '/x');

        $this->assertTrue($cursor->matchesIdentity($same));
        $this->assertFalse($cursor->matchesIdentity($rotated));
    }

    public function test_ip_allowlist_matches_ipv6_and_ipv4_cidr(): void
    {
        $mw = new IpAllowlist();
        $inCidr = new ReflectionMethod($mw, 'inCidr');
        $inCidr->setAccessible(true);

        $this->assertTrue($inCidr->invoke($mw, '2001:db8::1', '2001:db8::', 32));
        $this->assertFalse($inCidr->invoke($mw, '2001:db9::1', '2001:db8::', 32));
        $this->assertTrue($inCidr->invoke($mw, '10.0.0.5', '10.0.0.0', 24));
        $this->assertFalse($inCidr->invoke($mw, '10.0.1.5', '10.0.0.0', 24));
        // Cross-family rule never matches.
        $this->assertFalse($inCidr->invoke($mw, '10.0.0.5', '2001:db8::', 32));
    }

    public function test_secret_straddling_display_cap_is_fully_redacted(): void
    {
        $presenter = new EntryPresenter(new Redactor(['enabled' => true]), new SafeRenderer(), 40);
        // The "Bearer <token>" starts before the 40-byte cap and runs past it; an
        // old truncate-then-redact would leave the head of the token exposed.
        $raw = str_repeat('x', 30) . ' Bearer ' . str_repeat('S', 40);
        $parsed = new ParsedEntry(raw: $raw);
        $parsed->message = $raw;

        $out = $presenter->present(null, $parsed, ['raw' => $raw]);

        $this->assertTrue($out['truncated']);
        $this->assertStringNotContainsString('SSSS', $out['raw']);
    }
}
