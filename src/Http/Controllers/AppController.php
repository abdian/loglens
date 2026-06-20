<?php

namespace LogLens\Http\Controllers;

use Illuminate\Http\Request;
use LogLens\Diagnostics\Diagnostics;

/**
 * Serve the SPA shell (web-ui / package-setup). Bootstraps the Vue app with the
 * runtime config it needs: base URL, CSRF token, locale/dir, theme, asset URLs,
 * and feature flags. Disabled entirely under `api_only`.
 */
class AppController extends Controller
{
    public function index(Request $request)
    {
        $prefix = config('loglens.route.prefix', 'loglens');
        $assetBase = config('loglens.route.asset_url') ?: url($prefix . '/assets/' . Diagnostics::VERSION);

        $locale = config('loglens.locale.default') ?: app()->getLocale();
        $available = (array) config('loglens.locale.available', ['en']);
        if (! in_array($locale, $available, true)) {
            $locale = 'en';
        }

        $boot = [
            'baseUrl' => url($prefix),
            'apiUrl' => url($prefix . '/api'),
            'assetUrl' => $assetBase,
            'csrf' => csrf_token(),
            'locale' => $locale,
            'dir' => $locale === 'fa' ? 'rtl' : 'ltr',
            'theme' => config('loglens.theme', 'dark'),
            'version' => Diagnostics::VERSION,
            'editor' => config('loglens.editor.default', 'phpstorm'),
            'readOnly' => (bool) config('loglens.read_only', false),
        ];

        $response = response()->view('loglens::app', ['boot' => $boot, 'assetBase' => $assetBase]);

        // Always-safe hardening headers for the SPA shell.
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        // Content-Security-Policy: the shell is designed strict-CSP-safe (inert
        // JSON boot, external module script). Overridable via config; set to null
        // to disable, or widen script-src/style-src when serving assets from a CDN.
        $csp = config('loglens.route.csp', self::DEFAULT_CSP);
        if (! empty($csp)) {
            $response->headers->set('Content-Security-Policy', $csp);
        }

        return $response;
    }

    private const DEFAULT_CSP = "default-src 'self'; base-uri 'self'; object-src 'none'; "
        . "frame-ancestors 'self'; form-action 'self'; img-src 'self' data: blob:; "
        . "script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' data: https://fonts.gstatic.com; connect-src 'self'; frame-src 'self'";
}
