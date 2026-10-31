<?php

return [
    // Strict-Transport-Security max-age on https responses; 0 switches the header off.
    // No includeSubDomains/preload on purpose: ~27 sibling apps share the apex's subdomains.
    'hsts_seconds' => (int) env('SECURITY_HSTS_SECONDS', 15552000),
];
