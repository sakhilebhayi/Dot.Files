{{--
  The top bar of every signed-in page: the shared Dot header (dot-ui 0.6.0, <x-dot.header>), one row, search truly centred by the
  header grid, primary links, the platform switcher, the account menu; on a phone the shared drawer and full-screen search sheet.
  The Livewire component needs ONE root element, and the header prints the bar and its drawer side by side, so the wrapper
  is `display: contents` (.fx-chrome in app.css).
--}}
@php
    $user = Auth::user();
    $team = $user->currentTeam;

    // EA-3: the approvals link carries what waits for this person's decision (share requests and open activity flags),
    // for owners and administrators of a company only. Real counts, team-scoped like everything else here.
    $decides = $team && ! $team->personal_team && ($user->ownsTeam($team) || $user->hasTeamRole($team, 'admin'));
    $waiting = $decides
        ? App\Models\FileShare::pending()->whereIn('file_id', App\Models\File::query()->select('id'))->count()
            + App\Models\FileFlag::open()->where('team_id', $team->id)->count()
        : 0;

    $nav = [
        ['label' => __('Files'), 'href' => route('files'), 'current' => request()->routeIs('files')],
        ['label' => __('Shared with me'), 'href' => route('shared'), 'current' => request()->routeIs('shared')],
        ['label' => __('Sharing approvals'), 'href' => route('approvals'), 'current' => request()->routeIs('approvals'), 'badge' => $waiting > 0 ? ($waiting > 99 ? '99+' : $waiting) : null, 'attrs' => $waiting > 0 ? ['aria-label' => __('Sharing approvals, :n waiting', ['n' => $waiting])] : []],
        ['label' => __('Trash'), 'href' => route('trash'), 'current' => request()->routeIs('trash')],
    ];

    if ($user->is_platform_operator) {
        $nav[] = ['label' => __('Operator queue'), 'href' => route('operator.deletion-requests.index'), 'current' => request()->routeIs('operator.*')];
    }

    $links = [['label' => __('Profile'), 'href' => route('profile.show'), 'current' => request()->routeIs('profile.show')]];

    if (Laravel\Jetstream\Jetstream::hasApiFeatures()) {
        $links[] = ['label' => __('API tokens'), 'href' => route('api-tokens.index'), 'current' => request()->routeIs('api-tokens.index')];
    }

    if (Laravel\Jetstream\Jetstream::hasTeamFeatures()) {
        $links[] = ['rule' => true];
        $links[] = ['label' => __('Company settings'), 'href' => $team ? route('teams.show', $team->id) : route('teams.create')];

        if (Gate::forUser($user)->check('create', Laravel\Jetstream\Jetstream::newTeamModel())) {
            $links[] = ['label' => __('New company'), 'href' => route('teams.create')];
        }

        $teams = $user->allTeams();
        if ($teams->count() > 1) {
            $links[] = ['rule' => true];
            $links[] = ['heading' => __('Switch company')];
            foreach ($teams as $other) {
                $links[] = [
                    'label' => $other->name,
                    'current' => $user->isCurrentTeam($other),
                    'form' => ['action' => route('current-team.update'), 'method' => 'PUT', 'fields' => ['team_id' => $other->id]],
                ];
            }
        }
    }

    $account = [
        'name' => $user->name,
        'email' => $user->email,
        'team' => $team?->name,
        'links' => $links,
        'signOut' => ['action' => route('logout')],
    ];
@endphp
<div class="fx-chrome">
    <x-dot.header
        brand-href="{{ route('files') }}" brand-label="Dot.Files home"
        :nav="$nav" :account="$account" :context="$team?->name"
        drawer-title="{{ __('Menu') }}">
        <x-slot:brand>
            <img class="dh-logo-day" src="{{ asset('images/logo-header.png') }}" alt="Dot.Files" width="32" height="32">
            <img class="dh-logo-night" src="{{ asset('images/logo-header-light.png') }}" alt="" width="32" height="32">
        </x-slot:brand>

        {{-- The ecosystem search: the shared pill on this company's files (R-28 §3, R-51). Same logic on every page and width. --}}
        <x-slot:search>
            <livewire:file-search />
        </x-slot:search>

        {{-- Platform switcher (shared Dot UI): what InfoDot says the current company holds; a personal workspace has no company. --}}
        <x-slot:tools>
            <livewire:notification-bell />
            <x-dot.switcher platform="files"
                :company="$team && ! $team->personal_team ? $team->infodot_team_uuid : null"
                :company-name="$team?->name"
                logos="/dot-ui/img/platforms" />
        </x-slot:tools>
    </x-dot.header>
</div>
