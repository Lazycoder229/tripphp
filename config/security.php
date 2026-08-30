<?php

use Framework\Config\Env;

return [
    // HSTS only ever gets sent on an actual HTTPS request regardless of this flag —
    // this just lets you kill it entirely (e.g. local/staging behind plain HTTP).
    'hsts_enabled'    => (bool) Env::get('SECURITY_HSTS_ENABLED', true),

    'frame_options'   => Env::get('SECURITY_FRAME_OPTIONS', 'DENY'),
    'referrer_policy' => Env::get('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),

    'bcrypt_rounds'   => (int) Env::get('BCRYPT_ROUNDS', 12),

    // Content-Security-Policy — off by default. Set SECURITY_CSP_ENABLED=true
    // to send the header. SECURITY_CSP_REPORT_ONLY=true sends it as
    // Content-Security-Policy-Report-Only instead (logs violations, doesn't
    // block) — useful while first turning this on in production.
    'csp_enabled'     => (bool) Env::get('SECURITY_CSP_ENABLED', false),
    'csp_report_only' => (bool) Env::get('SECURITY_CSP_REPORT_ONLY', false),

    // Each key here is a CSP directive name. SecurityHeadersMiddleware skips
    // any directive left blank, and appends the per-request nonce (see
    // Framework\Security\Csp) to script-src automatically — no need to add
    // 'nonce-...' yourself. 'extra' is appended as-is, for anything not
    // covered by a named directive below (e.g. "worker-src 'self'").
    'csp_directives'  => [
        'default-src'     => Env::get('SECURITY_CSP_DEFAULT_SRC', "'self'"),
        'script-src'      => Env::get('SECURITY_CSP_SCRIPT_SRC', "'self'"),
        'style-src'       => Env::get('SECURITY_CSP_STYLE_SRC', "'self'"),
        'img-src'         => Env::get('SECURITY_CSP_IMG_SRC', "'self'"),
        'font-src'        => Env::get('SECURITY_CSP_FONT_SRC', "'self'"),
        'connect-src'     => Env::get('SECURITY_CSP_CONNECT_SRC', "'self'"),
        'frame-ancestors' => Env::get('SECURITY_CSP_FRAME_ANCESTORS', "'self'"),
        'form-action'     => Env::get('SECURITY_CSP_FORM_ACTION', "'self'"),
        'base-uri'        => Env::get('SECURITY_CSP_BASE_URI', "'self'"),
        'extra'           => Env::get('SECURITY_CSP_EXTRA', ''),
    ],
];