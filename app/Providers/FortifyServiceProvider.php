<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // R-27 §9: InfoDot is the only front door. In SSO-only mode the login PAGE sends the
        // visitor to InfoDot, where they sign in and InfoDot's launch page posts them back into
        // /auth/ecosystem. Everything underneath (password POST, resets, 2FA) stays wired, so an
        // operator can break glass with ECOSYSTEM_SSO_ONLY=false without a deploy.
        // Registered after every provider boots: Jetstream sets its own loginView in ITS boot,
        // and whoever registers last wins.
        $this->app->booted(function () {
            Fortify::loginView(function () {
                $infodot = (string) config('ecosystem.platforms.infodot.url', '');

                if ($infodot !== '' && config('ecosystem.sso_only')) {
                    return redirect()->away(rtrim($infodot, '/').'/login');
                }

                return view('auth.login');
            });
        });
    }
}
