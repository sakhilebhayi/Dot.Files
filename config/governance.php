<?php

// Document-governance monitoring (R-28 §29), owner policy 2026-10-31: the hide-the-evidence
// family only, review-only flags, alerts in the governance centre and by email to the company's
// owner and administrators. Thresholds are env-tunable per host; the defaults are deliberately
// conservative so a flag means something.
return [

    // One person saving new content for the SAME file this many times within the window.
    'version_churn_count' => (int) env('GOVERNANCE_VERSION_CHURN_COUNT', 5),
    'version_churn_minutes' => (int) env('GOVERNANCE_VERSION_CHURN_MINUTES', 60),

    // A file trashed within this many minutes of someone replacing its content.
    'replace_trash_minutes' => (int) env('GOVERNANCE_REPLACE_TRASH_MINUTES', 30),

    // Email the company's owner and administrators when a flag is raised.
    'alert_email' => (bool) env('GOVERNANCE_ALERT_EMAIL', true),

];
