<?php

use App\Sso\FilesProvisioner;

/*
 * Dot.Files' own choices for the shared sign-in package (packages/dot-sso-client). Everything else, including
 * DOTSSO_ENABLED (default false), comes from the package's defaults and the host .env. The session guard is the
 * package default (DOTSSO_GUARD, web): nothing is set here.
 */
return [
    'provisioner' => FilesProvisioner::class,

    // Files has always created an account for a person InfoDot vouches for (INFODOT_PROVISION_USERS), so that stays.
    'auto_provision' => (bool) env('DOTSSO_AUTO_PROVISION', env('INFODOT_PROVISION_USERS', true)),

    'landing' => '/files',

    // A deep link may open any signed-in page of the room, and nothing else. /o/{uuid} (an InfoDot object link) is
    // re-authorised by FileOpenController on arrival; the other paths are Files' own signed-in pages.
    'deep_link' => [
        'allow' => ['#^/(files|o|trash|shared|approvals|operator|user/profile|teams)(/|\?|$)#'],
        'deny' => ['#^/(sso|login|register|logout|forgot-password|reset-password|auth|oauth|two-factor-challenge|user/confirm)(/|\?|$)#i'],
    ],

    // Never intercepted. Files' machine doors (/api/*: the platform file API, the membership and search pushes, Sanctum)
    // and /up, /csp-report are outside the pages the package takes over. Files has no public share link: a share names a
    // verified person and is opened signed in. Nothing extra to list.
    'allow_paths' => [],
];
