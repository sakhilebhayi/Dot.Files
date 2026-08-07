<?php

namespace App\Providers;

use App\Http\Livewire\FileBrowser;
use App\Http\Livewire\NavigationDropdown;
use App\Models\File;
use App\Models\Folder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        // Re-register Jetstream's x-jet-* Blade component aliases.
        // These were registered by Jetstream 1.x but removed in Jetstream 4+.
        // Our published views still use the x-jet-* prefix so we restore them here.
        $this->callAfterResolving(BladeCompiler::class, function (BladeCompiler $blade) {
            $components = [
                'action-message', 'action-section', 'application-logo', 'application-mark',
                'authentication-card', 'authentication-card-logo', 'banner', 'button',
                'checkbox', 'confirmation-modal', 'confirms-password', 'danger-button',
                'dialog-modal', 'dropdown', 'dropdown-link', 'form-section', 'input',
                'input-error', 'label', 'modal', 'nav-link', 'responsive-nav-link',
                'secondary-button', 'section-border', 'section-title', 'switchable-team',
                'validation-errors', 'welcome',
            ];

            foreach ($components as $component) {
                $blade->component(
                    "vendor.jetstream.components.{$component}",
                    "jet-{$component}"
                );
            }
        });

        Relation::morphMap([
            'file' => File::class,
            'folder' => Folder::class,
        ]);

        // Livewire v3 changed the default component namespace from
        // App\Http\Livewire to App\Livewire. Register our components
        // explicitly so they are found regardless of auto-discovery path.
        Livewire::component('file-browser', FileBrowser::class);
        Livewire::component('navigation-dropdown', NavigationDropdown::class);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
