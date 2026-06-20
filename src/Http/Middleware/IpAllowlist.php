<?php

namespace LogLens\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional IP allowlist. Empty config = no restriction. Supports exact IPs and
 * CIDR ranges for both IPv4 and IPv6. Applied via config to the route group.
 */
class IpAllowlist
{
    public function handle(Request $request, Closure $next): Response
    {
        $allow = (array) config('loglens.security.ip_allowlist', []);
        if (empty($allow)) {
            return $next($request);
        }

        $ip = $request->ip();
        foreach ($allow as $rule) {
            if ($this->matches($ip, $rule)) {
                return $next($request);
            }
        }

        return response('Forbidden', 403);
    }

    private function matches(?string $ip, string $rule): bool
    {
        if ($ip === null) {
            return false;
        }
        if (strpos($rule, '/') === false) {
            // Normalize both sides (inet_pton) so equivalent IPv6 spellings and
            // IPv4 forms compare equal instead of relying on string identity.
            $a = @inet_pton($ip);
            $b = @inet_pton($rule);

            return $a !== false && $b !== false && $a === $b;
        }

        [$subnet, $bits] = explode('/', $rule, 2);

        return $this->inCidr($ip, $subnet, (int) $bits);
    }

    /** Family-agnostic CIDR containment via packed binary + bit masking. */
    private function inCidr(string $ip, string $subnet, int $bits): bool
    {
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        // Both must parse and be the same family (4 bytes IPv4 / 16 bytes IPv6).
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = max(0, min(strlen($ipBin) * 8, $bits));
        $whole = intdiv($bits, 8);
        $rem = $bits % 8;

        if ($whole > 0 && strncmp($ipBin, $subnetBin, $whole) !== 0) {
            return false;
        }
        if ($rem !== 0) {
            $mask = chr((0xff << (8 - $rem)) & 0xff);

            return ((($ipBin[$whole] ^ $subnetBin[$whole]) & $mask) === "\0");
        }

        return true;
    }
}
