<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The shared sign-in package's own suite tests the package's DEFAULT deep-link policy. This platform narrows
        // that policy (config/dotsso.php) to its own pages, so the package suite is run against the defaults.
        if (str_starts_with(static::class, 'Dot\\SsoClient\\Tests\\')) {
            config(['dotsso.landing' => '/dashboard']);
            config(['dotsso.deep_link' => [
                'allow' => ['#^/#'],
                'deny' => ['#^/(sso|login|register|logout|forgot-password|reset-password|auth|oauth|two-factor-challenge|user/confirm)(/|\\?|$)#i'],
            ]]);
        }
    }
}
