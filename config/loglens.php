<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Log discovery
    |--------------------------------------------------------------------------
    |
    | Glob patterns (relative to the configured roots, or absolute) used to
    | discover log files, plus exclude patterns. The roots constrain path
    | canonicalization: any file served must resolve inside one of them.
    |
    */

    'roots' => [
        storage_path('logs'),
    ],

    'include' => [
        '*.log',
        '**/*.log',
        '*.log.gz',
        '**/*.log.gz',
    ],

    'exclude' => [
        '**/*.lock',
    ],

    // Default sort for the file list: name|size|mtime, asc|desc.
    'sort' => ['by' => 'mtime', 'direction' => 'desc'],

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    */

    'route' => [
        'prefix' => 'loglens',
        'domain' => null,
        'middleware' => ['web'],
        // Asset URL base (CDN). Null = same origin via the package asset route.
        'asset_url' => null,
        // Content-Security-Policy for the SPA shell. Null/'' disables it; widen
        // script-src/style-src to the CDN host when asset_url points off-origin.
        // Default (a strict same-origin policy) is applied when this key is absent.
        // 'csp' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Operating modes
    |--------------------------------------------------------------------------
    |
    | api_only  — disable UI/asset routes, keep the JSON API.
    | read_only — deny every destructive endpoint regardless of gates.
    |
    */

    'api_only' => env('LOGLENS_API_ONLY', false),
    'read_only' => env('LOGLENS_READ_ONLY', false),

    /*
    |--------------------------------------------------------------------------
    | Index engine
    |--------------------------------------------------------------------------
    |
    | The persistent sidecar index lives on LOCAL disk only (never NFS/EFS —
    | SQLite corruption risk). Safe to delete; rebuilt on demand.
    |
    */

    'index' => [
        'directory' => storage_path('loglens'),
        // Global size budget for the whole index directory. LRU pruning kicks
        // in above this. Accepts ints (bytes) or "512M", "2G" strings.
        'size_budget' => '1G',
        // Force the binary fallback store even when pdo_sqlite is available
        // (useful for testing the degraded tier).
        'force_binary' => false,
        // Per-request time budget (ms) for the in-request indexing slicer.
        'in_request_budget_ms' => 200,
        // Batch size for index write transactions.
        'batch_size' => 2000,
        // Split files larger than this (bytes) into parallel segments when
        // queue workers are available.
        'segment_threshold' => 256 * 1024 * 1024,
        'segment_size' => 64 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Parsing
    |--------------------------------------------------------------------------
    */

    'parsing' => [
        // Source timezone for offset-less log timestamps (e.g. Laravel's default
        // "[2026-06-13 14:30:00]" with no offset). null = app timezone, then UTC.
        // Timestamps carrying an explicit offset or trailing "Z" ignore this.
        'timezone' => null,

        // Ordered list of built-in parser ids tried during auto-detection.
        // First with the highest confidence wins.
        'formats' => [
            'laravel',
            'json',
            'horizon_old',
            'http_access',
            'apache_error',
            'nginx_error',
            'php_fpm',
            'postgres',
            'redis',
            'supervisor',
        ],

        // Declarative custom formats. Each: name + entry-start PCRE with named
        // groups (datetime, level, message) + optional level map. No PHP class.
        //
        // 'custom' => [
        //     'myapp' => [
        //         'pattern' => '/^(?<datetime>\d{4}-\d{2}-\d{2}[ T][\d:]+)\s+(?<level>\w+)\s+(?<message>.*)/',
        //         'levels' => ['WARN' => 'warning'],
        //     ],
        // ],
        'custom' => [],

        // Class-based parsers (FQCN). Must implement LogLens\Contracts\Parser
        // or be wrapped via the opcodes adapter.
        'parsers' => [],

        // opcodesio/log-viewer Log-subclass FQCNs to adapt for migration.
        'opcodes_parsers' => [],

        // Per-entry display truncation (bytes). Full text fetched on demand.
        'max_display_bytes' => 64 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    'search' => [
        // Default case sensitivity (always Unicode-correct when insensitive).
        'case_sensitive' => false,
        // Hard cap on a streamed-PCRE-tier scan window (bytes) to bound CPU.
        'pcre_scan_cap' => 256 * 1024 * 1024,
        'default_limit' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Live tail
    |--------------------------------------------------------------------------
    */

    'tail' => [
        'window_seconds' => 45,
        'heartbeat_seconds' => 15,
        'read_cap_bytes' => 512 * 1024,
        'poll_active_ms' => 2000,
        'poll_idle_ms' => 10000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Error grouping / fingerprints
    |--------------------------------------------------------------------------
    */

    'fingerprint' => [
        // Drop line numbers from frames so deploys don't split groups.
        'deploy_stability' => false,
        // Per-exception-class strategy: class | class_message | class_frame.
        'rules' => [
            'Illuminate\\Database\\QueryException' => 'class_sql',
        ],
        // Substrings marking a stack frame as "vendor" (non-application).
        'vendor_markers' => ['/vendor/', '\\vendor\\'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    */

    'security' => [
        // Config kill-switches override gates to deny.
        'allow_download' => true,
        'allow_delete' => true,
        'allow_clear' => true,

        // Display-time secret redaction. ON by default; opt-out only.
        'redaction' => [
            'enabled' => true,
            // Additional user PCRE patterns (whole match replaced).
            'patterns' => [],
            // Toggle optional built-in pattern groups.
            'emails' => false,
            'cards' => true,
            'marker' => '[redacted]',
        ],

        // Optional IP allowlist (CIDR or exact). Empty = no allowlist.
        'ip_allowlist' => [],

        // Rate limits (requests per minute) for the throttled route groups.
        'rate_limits' => [
            'search' => 120,
            'tail' => 60,
            'index' => 30,
            // Error-grouping / histogram / sparkline / zip — each opens a store
            // and can fan out, so they get their own (generous) bucket.
            'analytics' => 120,
        ],

        // Signed download URL TTL (seconds).
        'download_ttl' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Editor deep links & path mapping
    |--------------------------------------------------------------------------
    */

    'editor' => [
        // phpstorm | vscode | vscode-insiders | cursor | sublime | idea
        'default' => 'phpstorm',
        // Remote → local path rewrites for editor links (e.g. Docker).
        // '/var/www/html' => 'C:\\www\\app'
        'path_mapping' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Localization
    |--------------------------------------------------------------------------
    */

    'locale' => [
        // null = auto-detect from app()->getLocale(); falls back to 'en'.
        'default' => null,
        'available' => ['en', 'fa'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Theme
    |--------------------------------------------------------------------------
    */

    'theme' => 'dark', // dark | light | system

];
