<?php

namespace LogLens\Tests\Feature;

use LogLens\Diagnostics\Diagnostics;
use LogLens\Tests\TestCase;

/**
 * Vendor-served, version-fingerprinted assets + CSP safety.
 */
class AssetTest extends TestCase
{
    private function buildDir(): string
    {
        return dirname(__DIR__, 2) . '/public/build';
    }

    public function test_bundle_is_csp_safe(): void
    {
        $js = $this->buildDir() . '/app.js';
        if (! is_file($js)) {
            $this->markTestSkipped('Frontend not built (run npm run build).');
        }
        $contents = file_get_contents($js);
        // Strict-CSP: no unsafe-eval surface in the shipped bundle.
        $this->assertDoesNotMatchRegularExpression('/\beval\s*\(/', $contents);
        $this->assertDoesNotMatchRegularExpression('/new\s+Function\s*\(/', $contents);
    }

    public function test_asset_route_serves_versioned_bundle_with_immutable_cache(): void
    {
        if (! is_file($this->buildDir() . '/app.js')) {
            $this->markTestSkipped('Frontend not built.');
        }
        $version = Diagnostics::VERSION;
        $res = $this->get("loglens/assets/{$version}/app.js");
        $res->assertOk();
        $this->assertStringContainsString('javascript', strtolower($res->headers->get('Content-Type')));
        $this->assertStringContainsString('immutable', (string) $res->headers->get('Cache-Control'));
    }

    public function test_asset_route_rejects_traversal(): void
    {
        $version = Diagnostics::VERSION;
        $res = $this->get("loglens/assets/{$version}/..%2f..%2fcomposer.json");
        $this->assertContains($res->status(), [403, 404]);
    }

    public function test_shell_references_version_fingerprinted_assets(): void
    {
        // Stale-asset impossibility: the asset base must carry the version, so a
        // composer upgrade changes every asset URL.
        $res = $this->get('loglens');
        $res->assertOk();
        $res->assertSee(Diagnostics::VERSION, false);
        $res->assertSee('/app.js', false);
    }
}
