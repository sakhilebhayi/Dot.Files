<nav x-data="{ open: false, account: false }" class="fx-top" @keydown.escape.window="open = false; account = false">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-12 gap-4">
            <div class="flex items-center gap-5 min-w-0">
                <a href="{{ route('files') }}" class="flex items-center press" aria-label="Dot.Files home">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Files" class="h-8 w-auto">
                </a>

                <div class="hidden sm:flex items-center gap-1">
                    <a href="{{ route('files') }}" class="fx-top-link" @if (request()->routeIs('files')) aria-current="page" @endif>
                        {{ __('Files') }}
                    </a>
                    <a href="{{ route('shared') }}" class="fx-top-link" @if (request()->routeIs('shared')) aria-current="page" @endif>
                        {{ __('Shared with me') }}
                    </a>
                    <a href="{{ route('approvals') }}" class="fx-top-link" @if (request()->routeIs('approvals')) aria-current="page" @endif>
                        {{ __('Sharing approvals') }}
                    </a>
                    <a href="{{ route('trash') }}" class="fx-top-link" @if (request()->routeIs('trash')) aria-current="page" @endif>
                        {{ __('Trash') }}
                    </a>
                    @if (Auth::user()->is_platform_operator)
                        <a href="{{ route('operator.deletion-requests.index') }}" class="fx-top-link" @if (request()->routeIs('operator.*')) aria-current="page" @endif>
                            {{ __('Operator queue') }}
                        </a>
                    @endif
                </div>
            </div>

            {{-- The ecosystem search bar: InfoDot's component, on this company's files (R-28 §3). --}}
            <div class="ix-top__search hidden md:block">
                <livewire:file-search />
            </div>

            {{-- Account / company menu --}}
            <div class="hidden sm:block relative">
                <button type="button" class="fx-top-link flex items-center gap-2" @click="account = ! account"
                        :aria-expanded="account.toString()" aria-haspopup="menu">
                    <span class="inline-grid place-items-center w-6 h-6 rounded-md text-xs font-semibold"
                          style="background: var(--oxide-wash); color: var(--oxide);">
                        {{ mb_strtoupper(mb_substr(Auth::user()->currentTeam?->name ?? Auth::user()->name, 0, 1)) }}
                    </span>
                    <span class="max-w-[14rem] truncate">{{ Auth::user()->currentTeam?->name ?? Auth::user()->name }}</span>
                    <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg>
                </button>

                <div x-cloak x-show="account" @click.outside="account = false"
                     x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                     class="fx-menu absolute right-0 mt-2" role="menu">
                    <p class="px-2.5 pt-1.5 pb-1 text-xs" style="color: var(--ink-faint);">{{ Auth::user()->name }} · {{ Auth::user()->email }}</p>

                    <a href="{{ route('profile.show') }}" role="menuitem">{{ __('Profile') }}</a>
                    @if (Laravel\Jetstream\Jetstream::hasApiFeatures())
                        <a href="{{ route('api-tokens.index') }}" role="menuitem">{{ __('API tokens') }}</a>
                    @endif

                    @if (Laravel\Jetstream\Jetstream::hasTeamFeatures())
                        <div class="my-1 border-t" style="border-color: var(--line);"></div>
                        <a href="{{ Auth::user()->currentTeam ? route('teams.show', Auth::user()->currentTeam->id) : route('teams.create') }}" role="menuitem">{{ __('Company settings') }}</a>
                        @can('create', Laravel\Jetstream\Jetstream::newTeamModel())
                            <a href="{{ route('teams.create') }}" role="menuitem">{{ __('New company') }}</a>
                        @endcan

                        @if (Auth::user()->allTeams()->count() > 1)
                            <div class="my-1 border-t" style="border-color: var(--line);"></div>
                            <p class="px-2.5 pt-1 pb-0.5 text-xs" style="color: var(--ink-faint);">{{ __('Switch company') }}</p>
                            @foreach (Auth::user()->allTeams() as $team)
                                <form method="POST" action="{{ route('current-team.update') }}">
                                    @method('PUT')
                                    @csrf
                                    <input type="hidden" name="team_id" value="{{ $team->id }}">
                                    <button type="submit" role="menuitem">
                                        @if (Auth::user()->isCurrentTeam($team))
                                            <x-fx.icon name="check" class="w-4 h-4" style="color: var(--moss);" />
                                        @else
                                            <span class="w-4"></span>
                                        @endif
                                        <span class="truncate">{{ $team->name }}</span>
                                    </button>
                                </form>
                            @endforeach
                        @endif
                    @endif

                    <div class="my-1 border-t" style="border-color: var(--line);"></div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" role="menuitem">{{ __('Sign out') }}</button>
                    </form>
                </div>
            </div>

            {{-- Mobile toggle --}}
            <button @click="open = ! open" aria-label="{{ __('Toggle navigation menu') }}" :aria-expanded="open.toString()"
                    class="sm:hidden fx-top-link">
                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                    <path :class="{ 'hidden': open }" d="M4 7h16M4 12h16M4 17h16" />
                    <path :class="{ 'hidden': ! open }" class="hidden" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </div>

    {{-- Mobile menu --}}
    <div x-cloak x-show="open" class="sm:hidden border-t px-4 pb-4 pt-2 space-y-1" style="border-color: var(--line);">
        <a href="{{ route('files') }}" class="fx-top-link block">{{ __('Files') }}</a>
        <a href="{{ route('shared') }}" class="fx-top-link block">{{ __('Shared with me') }}</a>
        <a href="{{ route('approvals') }}" class="fx-top-link block">{{ __('Sharing approvals') }}</a>
        <a href="{{ route('trash') }}" class="fx-top-link block">{{ __('Trash') }}</a>
        @if (Auth::user()->is_platform_operator)
            <a href="{{ route('operator.deletion-requests.index') }}" class="fx-top-link block">{{ __('Operator queue') }}</a>
        @endif
        <div class="border-t my-2" style="border-color: var(--line);"></div>
        <p class="px-2.5 text-xs" style="color: var(--bar-faint);">{{ Auth::user()->name }} · {{ Auth::user()->currentTeam?->name }}</p>
        <a href="{{ route('profile.show') }}" class="fx-top-link block">{{ __('Profile') }}</a>
        @if (Laravel\Jetstream\Jetstream::hasTeamFeatures())
            <a href="{{ Auth::user()->currentTeam ? route('teams.show', Auth::user()->currentTeam->id) : route('teams.create') }}" class="fx-top-link block">{{ __('Company settings') }}</a>
            @foreach (Auth::user()->allTeams() as $team)
                @unless (Auth::user()->isCurrentTeam($team))
                    <form method="POST" action="{{ route('current-team.update') }}">
                        @method('PUT')
                        @csrf
                        <input type="hidden" name="team_id" value="{{ $team->id }}">
                        <button type="submit" class="fx-top-link block w-full text-left">{{ __('Switch to') }} {{ $team->name }}</button>
                    </form>
                @endunless
            @endforeach
        @endif
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="fx-top-link block w-full text-left">{{ __('Sign out') }}</button>
        </form>
    </div>
</nav>
