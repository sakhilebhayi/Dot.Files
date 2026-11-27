<?php

return [
    // Strict-Transport-Security max-age on https responses; 0 switches the header off.
    // No includeSubDomains/preload on purpose: ~27 sibling apps share the apex's subdomains.
    'hsts_seconds' => (int) env('SECURITY_HSTS_SECONDS', 15552000),

    // EA-1: the Content-Security-Policy is enforced. SECURITY_CSP_ENFORCE=false returns it to report-only without a
    // deploy (config:cache) if a real page is ever blocked; reports keep flowing to /csp-report either way.
    'csp_enforce' => (bool) env('SECURITY_CSP_ENFORCE', true),

    // EA-1: PDF snapshots are rendered by Ghostscript through Imagick. FILES_PDF_PREVIEWS=false turns them off (the
    // document glyph is shown instead); the limits below bound what one render may use.
    'pdf_previews' => (bool) env('FILES_PDF_PREVIEWS', true),
    'pdf_limits' => [
        'memory_mb' => (int) env('FILES_PDF_MEMORY_MB', 256),
        'disk_mb' => (int) env('FILES_PDF_DISK_MB', 512),
        'seconds' => (int) env('FILES_PDF_SECONDS', 20),
    ],
];
