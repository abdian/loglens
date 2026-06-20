<?php

namespace LogLens\Security;

use Illuminate\Support\Facades\Gate;

/**
 * Central authorization policy.
 *
 * - `viewLogLens` is production default-deny until the host defines a gate.
 * - Per-action gates (opcodes-compatible names) default to allow once the
 *   viewer gate passes, so existing opcodes installs migrate cleanly.
 * - Config kill switches (`allow_*`) and global `read_only` override gates to
 *   DENY and cannot be overridden by a host gate returning true.
 */
class Authorizer
{
    public const VIEW = 'viewLogLens';

    public const GATES = [
        'viewLogLens',
        'downloadLogFile',
        'downloadLogFolder',
        'deleteLogFile',
        'deleteLogFolder',
        'deleteLogEntry',
        'clearLogFile',
        'pruneLogFiles',
    ];

    /** Map an ability to the kill-switch that can force-deny it. */
    private const KILL_SWITCH = [
        'downloadLogFile' => 'allow_download',
        'downloadLogFolder' => 'allow_download',
        'deleteLogFile' => 'allow_delete',
        'deleteLogFolder' => 'allow_delete',
        'deleteLogEntry' => 'allow_delete',
        'clearLogFile' => 'allow_clear',
        'pruneLogFiles' => 'allow_delete',
    ];

    private const DESTRUCTIVE = [
        'deleteLogFile', 'deleteLogFolder', 'deleteLogEntry', 'clearLogFile', 'pruneLogFiles',
    ];

    public function __construct(private array $config = [])
    {
    }

    /**
     * Final allow/deny including config overrides. Used by controllers and the
     * middleware; the Gate facade enforces any host-defined callback.
     */
    public function allows(string $ability, $user = null, $argument = null): bool
    {
        // Global read-only forbids every destructive/export-mutating action.
        if (! empty($this->config['read_only']) && in_array($ability, self::DESTRUCTIVE, true)) {
            return false;
        }

        // Kill switches force-deny regardless of gate result.
        $switch = self::KILL_SWITCH[$ability] ?? null;
        if ($switch !== null && ($this->config[$switch] ?? true) === false) {
            return false;
        }

        return $argument !== null
            ? Gate::forUser($user)->allows($ability, $argument)
            : Gate::forUser($user)->allows($ability);
    }

    /** Default gate policy registered when the host has not defined the gate. */
    public static function defaultPolicy(string $ability, $user = null): bool
    {
        if ($ability === self::VIEW) {
            // Production default-deny: allow only in the local environment until
            // the host defines viewLogLens.
            return self::isLocal();
        }

        // Per-action gates inherit from the viewer gate (already enforced by the
        // middleware) and default to allow.
        return true;
    }

    public function readOnly(): bool
    {
        return (bool) ($this->config['read_only'] ?? false);
    }

    public function killSwitch(string $ability): bool
    {
        $switch = self::KILL_SWITCH[$ability] ?? null;

        return $switch !== null ? ($this->config[$switch] ?? true) : true;
    }

    private static function isLocal(): bool
    {
        if (function_exists('app')) {
            try {
                return app()->environment('local', 'testing');
            } catch (\Throwable $e) {
                return false;
            }
        }

        return false;
    }
}
