@php
    $team = $this->currentTeam;
    $user = auth()->user();
    $canShare = $team && ! $team->personal_team && ($user->ownsTeam($team) || $user->hasTeamRole($team, 'admin'));
    $canRequest = $team && ! $team->personal_team && ! $canShare;
    $inCompany = $team && ! $team->personal_team;
    $stats = $this->stats;
    $pinnedIds = \App\Models\FilePin::where('user_id', $user->id)->pluck('file_id')->all();
    $firstRun = $this->isFirstRun;
    $firstName = \Illuminate\Support\Str::of($user->name)->trim()->before(' ')->toString();
    $infodot = rtrim((string) config('ecosystem.platforms.infodot.url'), '/');
    // The header's Dot Apps switcher carries InfoDot for a linked company; a personal or unlinked workspace gets only a sentence there, so it keeps this way back.
    $keepWayback = ! $team || $team->personal_team || blank($team->infodot_team_uuid);
    $focus = $firstRun ? null : $this->focused;
@endphp

<div class="fx-deskroot"
     @keydown.slash.window="if (!['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) { $event.preventDefault(); document.getElementById('fx-search')?.focus() }"
     @dragenter.window="if (Array.from($event.dataTransfer?.types ?? []).includes('Files')) $wire.showingObjectloadForm = true">
    <a class="fx-skip" href="#fx-records">{{ __('Skip to the records') }}</a>

    <div class="fx-region @if ($firstRun) fx-region--plain @endif">
    @if ($firstRun)
        {{-- First run (R-50): nothing has ever been filed, so the arrival is the whole screen —
             what this is, whose it is, the one action, and the house rules stated plainly. --}}
        <section class="fx-panel fx-firstrun" aria-labelledby="fx-arrival-title" data-landing="first-run">
            <div class="fx-firstrun-lead">
                <picture>
                    <source srcset="{{ asset('images/logo-light.png') }}" media="(prefers-color-scheme: dark)">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Files" width="88" height="88" class="fx-arrival-logo fx-arrival-logo--lg" data-landing-logo>
                </picture>
                <h1 id="fx-arrival-title" class="fx-firstrun-title">
                    {{ __('Hello :name. The filing room for :company is ready.', ['name' => $firstName, 'company' => $team?->name]) }}
                </h1>
                <p class="fx-firstrun-copy">
                    {{ __('Dot.Files is where the company keeps its documents: upload them, sort them into folders, find them again and share them with the people who need them.') }}
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="fx-btn fx-btn--oxide fx-arrival-action" data-primary-action
                            wire:click="$set('showingObjectloadForm', true)">
                        <x-fx.icon name="upload" /> {{ __('Upload the first document') }}
                    </button>
                    <button type="button" class="fx-btn fx-arrival-action" wire:click="$set('creatingNewFolder', true)">
                        <x-fx.icon name="folder" /> {{ __('New folder') }}
                    </button>
                </div>
                @if ($keepWayback)
                    <p class="fx-wayback">
                        <a href="{{ $infodot }}/home">{{ __('InfoDot') }}</a>
                        <a href="{{ $infodot }}/platforms">{{ __('Platforms') }}</a>
                    </p>
                @endif
                <x-fx.spines :spines="collect()" :slots="14" class="fx-empty-art fx-firstrun-art" />
            </div>

            <div class="fx-firstrun-facts">
                <h2 class="font-bold">{{ __('Nothing is filed here yet.') }}</h2>
                <dl>
                    <div>
                        <dt>{{ __('What you can file') }}</dt>
                        <dd>{{ __('Documents, spreadsheets, images, archives and media, up to 100 MB each.') }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('Who can see it') }}</dt>
                        <dd>{{ __('Only the people in :company. No other business sees a file or its name.', ['company' => $team?->name]) }}</dd>
                    </div>
                    @if ($canShare)
                        <div>
                            <dt>{{ __('Sharing outside') }}</dt>
                            <dd>{{ __('You share a file with a named person by email, for 7 to 90 days, and can end it at any time.') }}</dd>
                        </div>
                    @elseif ($canRequest)
                        <div>
                            <dt>{{ __('Sharing outside') }}</dt>
                            <dd>{{ __('You can ask to share a file with a named person. An owner or administrator decides.') }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt>{{ __('Deleting') }}</dt>
                        <dd>{{ __('A deleted file waits in the trash for 30 days and can be restored until then.') }}</dd>
                    </div>
                </dl>
            </div>
        </section>
    @else
        {{-- The arrival (R-50, R-51): the real logo, the one sentence that says what needs a person, the one action,
             and the company's documents drawn as spines. Compact on purpose: people open this screen all day. --}}
        <section class="fx-arrive" aria-labelledby="fx-headline" data-landing="band">
            <picture class="fx-arrive-logo">
                <source srcset="{{ asset('images/logo-light.png') }}" media="(prefers-color-scheme: dark)">
                <img src="{{ asset('images/logo.png') }}" alt="Dot.Files" width="56" height="56" class="fx-arrival-logo" data-landing-logo>
            </picture>
            <div class="fx-arrive-text">
                <h1 id="fx-headline" class="fx-headline">{{ $this->headline }}</h1>
                <p class="fx-subline">
                    {{ __(':company keeps :count here. Only the people in the company can see them.', ['company' => $team?->name, 'count' => trans_choice(':count document|:count documents', $stats['documents'], ['count' => number_format($stats['documents'])])]) }}
                </p>
                @if ($keepWayback)
                    <p class="fx-wayback">
                        <a href="{{ $infodot }}/home">{{ __('InfoDot') }}</a>
                        <a href="{{ $infodot }}/platforms">{{ __('Platforms') }}</a>
                    </p>
                @endif
            </div>
            <x-fx.spines :spines="$this->spines" class="fx-arrive-art" />
            <div class="fx-arrive-side">
                <button type="button" class="fx-btn fx-btn--oxide fx-arrival-action" data-primary-action
                        wire:click="$set('showingObjectloadForm', true)">
                    <x-fx.icon name="upload" /> {{ __('Upload documents') }}
                </button>
                @if ($canShare)
                    <a href="{{ route('approvals') }}" class="fx-quiet-link">{{ __('Review in Sharing approvals') }}</a>
                @endif
            </div>
        </section>

        <div class="fx-desk">
            <section class="fx-master" aria-label="{{ __('Records') }}">
                <div class="fx-master-top">
                @if ($bulkDeletionRequested)
                    <div class="fx-note" role="status">
                        {{ __('Deletion requested. A platform operator checks every bulk removal before anything is taken off the record — nothing is gone yet.') }}
                    </div>
                @endif

                @if ($shareNotice)
                    <div class="fx-note fx-note--good" role="status">{{ $shareNotice }}</div>
                @endif

                @if ($creatingNewFolder)
                    <form class="fx-newfolder" wire:submit.prevent="createFolder">
                        <x-fx.icon name="folder" style="color: var(--ink-soft);" />
                        <label for="new-folder-name" class="sr-only">{{ __('Folder name') }}</label>
                        <input id="new-folder-name" type="text" class="fx-input flex-grow min-w-[12rem]" placeholder="{{ __('Folder name') }}"
                               wire:model="newFolderState.name" x-init="$el.focus()">
                        <button type="submit" class="fx-btn fx-btn--oxide">{{ __('Create folder') }}</button>
                        <button type="button" class="fx-btn" wire:click="$set('creatingNewFolder', false)">{{ __('Cancel') }}</button>
                        @error('newFolderState.name')<p class="w-full text-sm" style="color: var(--danger);">{{ $message }}</p>@enderror
                    </form>
                @endif

                @if (count($selectedObjects) > 0)
                <div class="fx-selbar" role="toolbar" aria-label="{{ __('Selection actions') }}">
                    <p class="text-sm font-medium flex-grow" style="font-variant-numeric: tabular-nums;">
                        {{ trans_choice(':count item selected|:count items selected', count($selectedObjects), ['count' => count($selectedObjects)]) }}
                    </p>
                    <button type="button" class="fx-btn fx-btn--quiet" wire:click="$set('selectedObjects', [])">{{ __('Clear') }}</button>
                    <button type="button" class="fx-btn" wire:click="$toggle('sharingSelection')">
                        <x-fx.icon name="share" /> {{ __('Share selected') }}
                    </button>
                    <button type="button" class="fx-btn fx-btn--danger-outline !min-h-[36px]" wire:click="requestBulkDeletion">
                        {{ __('Request deletion') }}
                    </button>

                    @if ($sharingSelection)
                        <form class="w-full flex flex-wrap items-end gap-3 border-t pt-3 mt-1" style="border-color: var(--line);"
                              wire:submit.prevent="shareSelected">
                            <div>
                                <label for="bulk-share-email" class="block text-sm font-medium">{{ __('Their email') }}</label>
                                <input id="bulk-share-email" type="email" wire:model="shareEmail" required class="fx-input mt-1 w-64" autocomplete="off">
                            </div>
                            <div>
                                <label for="bulk-share-days" class="block text-sm font-medium">{{ __('Access lasts') }}</label>
                                <select id="bulk-share-days" wire:model="shareDays" class="fx-select mt-1">
                                    <option value="7">{{ __('7 days') }}</option>
                                    <option value="30">{{ __('30 days') }}</option>
                                    <option value="60">{{ __('60 days') }}</option>
                                    <option value="90">{{ __('90 days (longest)') }}</option>
                                </select>
                            </div>
                            <div>
                                <label for="bulk-share-permission" class="block text-sm font-medium">{{ __('They may') }}</label>
                                <select id="bulk-share-permission" wire:model="sharePermission" class="fx-select mt-1">
                                    <option value="download">{{ __('View and download') }}</option>
                                    <option value="view">{{ __('View only') }}</option>
                                </select>
                            </div>
                            <button type="submit" class="fx-btn fx-btn--oxide" wire:loading.attr="disabled">{{ __('Share') }}</button>
                            <p class="w-full text-xs mt-0" style="color: var(--ink-faint);">
                                {{ __('Each record becomes its own share with the usual rules: folders are left out, and where approval is required the records stay invisible until an owner or administrator decides.') }}
                            </p>
                            @error('shareEmail')<p class="w-full text-sm" style="color: var(--danger);">{{ $message }}</p>@enderror
                            @error('shareDays')<p class="w-full text-sm" style="color: var(--danger);">{{ $message }}</p>@enderror
                            @error('sharePermission')<p class="w-full text-sm" style="color: var(--danger);">{{ $message }}</p>@enderror
                        </form>
                    @endif
                </div>
                @else
                <div class="fx-toolbar" role="toolbar" aria-label="{{ __('Find and arrange') }}">
                    <nav class="fx-crumbs fx-crumbs--bar" aria-label="{{ __('Folder path') }}">
                        @if ($this->query)
                            <span>
                                {{ trans_choice(':count record matches|:count records match', $this->results->count(), ['count' => $this->results->count()]) }}
                                “{{ $this->query }}”
                            </span>
                            <button type="button" class="fx-btn fx-btn--quiet" wire:click="$set('query', null)">{{ __('Clear search') }}</button>
                        @else
                            @foreach ($ancestors as $ancestor)
                                <a href="{{ route('files', ['uuid' => $ancestor->uuid]) }}" @if ($loop->last) aria-current="page" @endif>
                                    {{ $loop->first ? __('All records') : $ancestor->objectable->name }}
                                </a>
                                @unless ($loop->last)
                                    <span aria-hidden="true" style="color: var(--ink-faint);">/</span>
                                @endunless
                            @endforeach
                        @endif
                    </nav>

                    <div class="fx-filter">
                        <x-dot.filter-search id="fx-search" name="query" :placeholder="__('Search this company’s records — press /')"
                                             :label="__('Search this company’s records')" wire:model.live.debounce.300ms="query" />
                    </div>

                    <label>
                        <span class="sr-only">{{ __('Show only') }}</span>
                        <select class="fx-select !w-auto" wire:model.live="kind">
                            <option value="all">{{ __('Everything') }}</option>
                            <option value="pdf">{{ __('PDFs') }}</option>
                            <option value="doc">{{ __('Documents') }}</option>
                            <option value="sheet">{{ __('Spreadsheets') }}</option>
                            <option value="image">{{ __('Images') }}</option>
                            <option value="media">{{ __('Media') }}</option>
                            <option value="archive">{{ __('Archives') }}</option>
                        </select>
                    </label>

                    <div class="fx-seg" role="group" aria-label="{{ __('View') }}">
                        <button type="button" wire:click="setView('grid')" class="fx-btn fx-btn--quiet"
                                aria-pressed="{{ $view === 'grid' ? 'true' : 'false' }}" title="{{ __('Shelf view') }}">
                            <x-fx.icon name="grid" /><span class="sr-only">{{ __('Shelf view') }}</span>
                        </button>
                        <button type="button" wire:click="setView('list')" class="fx-btn fx-btn--quiet"
                                aria-pressed="{{ $view === 'list' ? 'true' : 'false' }}" title="{{ __('Register view') }}">
                            <x-fx.icon name="list" /><span class="sr-only">{{ __('Register view') }}</span>
                        </button>
                    </div>

                    <button type="button" class="fx-btn" wire:click="$set('creatingNewFolder', true)">
                        <x-fx.icon name="folder" /> {{ __('New folder') }}
                    </button>
                </div>
                @endif
                </div>

                <div class="fx-scroller">

        {{-- The records --}}
        @if ($this->results->count() === 0)
            <div class="fx-empty fx-empty--records">
                <x-fx.spines :spines="collect()" :slots="9" class="fx-empty-art" />
                @if ($this->query)
                    <p class="font-semibold">{{ __('No records match') }} “{{ $this->query }}”.</p>
                    <p class="text-sm">{{ __('Try fewer words, or look through the folders — the search covers names and folder paths.') }}</p>
                    <button type="button" class="fx-btn" wire:click="$set('query', null)">{{ __('Clear search') }}</button>
                @else
                    <p class="font-semibold">{{ __('Nothing is filed here yet.') }}</p>
                    <p class="text-sm">{{ __('Documents you upload here appear snapshot first, and only your company can see them.') }}</p>
                    <button type="button" class="fx-btn" wire:click="$set('showingObjectloadForm', true)">
                        <x-fx.icon name="upload" /> {{ __('Upload to this folder') }}
                    </button>
                @endif
            </div>
        @elseif ($view === 'grid')
            <ul class="fx-shelf" role="list">
                @foreach ($this->results as $child)
                    <li class="fx-card @if ($child->objectable_type === 'folder') fx-folder @endif" wire:key="card-{{ $child->id }}">
                        <label class="fx-card-check absolute top-2 left-2 z-10 p-1.5 rounded" style="background: color-mix(in srgb, var(--panel) 75%, transparent);">
                            <input type="checkbox" class="fx-check" wire:model.live="selectedObjects" value="{{ $child->id }}"
                                   aria-label="{{ __('Select') }} {{ $child->objectable->name }}">
                        </label>

                        @if ($child->objectable_type === 'folder')
                            <a href="{{ route('files', ['uuid' => $child->uuid]) }}" class="fx-card-face" tabindex="-1" aria-hidden="true">
                                <x-fx.icon name="folder" class="w-9 h-9" />
                            </a>
                        @elseif ($child->objectable->hasPreview())
                            <a href="{{ route('files.open', $child->objectable->uuid) }}" class="fx-card-face" tabindex="-1" aria-hidden="true">
                                <img src="{{ route('files.preview', $child->objectable) }}" alt="" loading="lazy" width="230" height="150">
                            </a>
                        @else
                            <a href="{{ route('files.open', $child->objectable->uuid) }}" class="fx-card-face" tabindex="-1" aria-hidden="true">
                                <x-fx.icon :name="$child->objectable->kind()" class="w-9 h-9" />
                            </a>
                        @endif

                        <div class="fx-card-body">
                            @if ($renamingObject === $child->id)
                                <form class="flex items-center gap-1.5" wire:submit.prevent="renameObject">
                                    <label class="sr-only" for="rename-{{ $child->id }}">{{ __('New name') }}</label>
                                    <input id="rename-{{ $child->id }}" type="text" class="fx-input !min-h-[36px] text-sm" wire:model="renamingObjectState.name" x-init="$el.focus()">
                                    <button type="submit" class="fx-btn fx-btn--quiet" title="{{ __('Save name') }}"><x-fx.icon name="check" /></button>
                                    <button type="button" class="fx-btn fx-btn--quiet" wire:click="$set('renamingObject', null)" title="{{ __('Cancel') }}"><x-fx.icon name="x" /></button>
                                </form>
                            @else
                                @if ($child->objectable_type === 'folder')
                                    <a href="{{ route('files', ['uuid' => $child->uuid]) }}" class="fx-card-title">
                                        <span class="sm:hidden inline-block align-[-3px] mr-1" style="color: var(--ink-soft);"><x-fx.icon name="folder" class="w-4 h-4 inline" /></span>{{ $child->objectable->name }}
                                    </a>
                                    <p class="fx-card-meta">{{ __('Folder') }} · {{ $child->created_at->format('j M Y') }}</p>
                                @else
                                    <a href="{{ route('files.open', $child->objectable->uuid) }}" class="fx-card-title">{{ $child->objectable->name }}</a>
                                    <p class="fx-card-meta">{{ $child->objectable->kindLabel() }} · {{ $child->objectable->sizeForHumans() }} · {{ $child->created_at->format('j M Y') }}@if ($child->objectable->originLabel()) · {{ __('from') }} {{ $child->objectable->originLabel() }}@endif</p>
                                @endif
                            @endif

                            <div class="flex items-center justify-between gap-2 mt-1 min-w-0">
                                @if ($child->objectable_type === 'file' && $child->objectable->infodot_reference)
                                    <span class="fx-ref truncate" title="{{ __('InfoDot reference — paste it into InfoDot search to open this file') }}">{{ $child->objectable->infodot_reference }}</span>
                                @else
                                    <span></span>
                                @endif

                                @include('livewire.partials.row-menu')
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <table class="fx-register" id="fx-records">
                <caption class="sr-only">{{ __('Records') }}</caption>
                <thead>
                    <tr>
                        <th class="fx-c-check"><span class="sr-only">{{ __('Select') }}</span></th>
                        <th aria-sort="{{ $sort === 'name' ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                            <button type="button" class="fx-sort" wire:click="sortBy('name')">{{ __('Name') }}</button>
                        </th>
                        <th class="fx-c-size fx-num" aria-sort="{{ $sort === 'size' ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                            <button type="button" class="fx-sort" wire:click="sortBy('size')">{{ __('Size') }}</button>
                        </th>
                        <th class="fx-c-filed" aria-sort="{{ $sort === 'filed' ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                            <button type="button" class="fx-sort" wire:click="sortBy('filed')">{{ __('Filed') }}</button>
                        </th>
                        @if ($inCompany)
                            <th class="fx-c-out">{{ __('Outside the company') }}</th>
                        @endif
                        <th class="fx-c-act"><span class="sr-only">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->results as $child)
                        @php
                            $isFolder = $child->objectable_type === 'folder';
                            $file = $isFolder ? null : $child->objectable;
                            $out = $file ? ($this->outsideMap[$file->id] ?? null) : null;
                            $link = $isFolder ? route('files', ['uuid' => $child->uuid]) : route('files.open', $file->uuid);
                            // A click selects (the panel shows the record); a folder, or any click below 901px where the panel is not shown, opens instead.
                            $rowClick = "if (\$event.target.closest('a,button,input,label,form')) return; ".($isFolder
                                ? "\$el.querySelector('a.fx-name')?.click()"
                                : "if (window.matchMedia('(max-width: 900px)').matches) { \$el.querySelector('a.fx-name')?.click(); return } \$wire.focus({$child->id})");
                            $rowEnter = $isFolder ? "\$el.querySelector('a.fx-name')?.click()" : "\$wire.focus({$child->id})";
                        @endphp
                        <tr wire:key="row-{{ $child->id }}" class="fx-row" style="--i: {{ $loop->index }}" tabindex="0"
                            @if (! $isFolder) aria-selected="{{ $this->focusId === $child->id ? 'true' : 'false' }}" @endif
                            @click="{{ $rowClick }}"
                            @keydown.enter.self="{{ $rowEnter }}"
                            @keydown.down.prevent="$el.nextElementSibling?.focus()"
                            @keydown.up.prevent="$el.previousElementSibling?.focus()"
                            @keydown.escape="$wire.clearFocus()">
                            <td class="fx-c-check">
                                <input type="checkbox" class="fx-check" wire:model.live="selectedObjects" value="{{ $child->id }}"
                                       aria-label="{{ __('Select') }} {{ $child->objectable->name }}">
                            </td>
                            <td>
                                <div class="fx-cell-name">
                                    @if ($file && $file->hasPreview())
                                        <img src="{{ route('files.preview', $file) }}" alt="" class="fx-thumb" width="30" height="38" loading="lazy" decoding="async">
                                    @else
                                        <span class="fx-kind-ico"><x-fx.icon :name="$isFolder ? 'folder' : $file->kind()" /></span>
                                    @endif
                                    <div class="min-w-0">
                                        @if ($renamingObject === $child->id)
                                            <form class="flex items-center gap-1.5" wire:submit.prevent="renameObject">
                                                <label class="sr-only" for="rename-row-{{ $child->id }}">{{ __('New name') }}</label>
                                                <input id="rename-row-{{ $child->id }}" type="text" class="fx-input !min-h-[36px] text-sm" wire:model="renamingObjectState.name" x-init="$el.focus()">
                                                <button type="submit" class="fx-btn fx-btn--quiet" title="{{ __('Save name') }}"><x-fx.icon name="check" /></button>
                                                <button type="button" class="fx-btn fx-btn--quiet" wire:click="$set('renamingObject', null)" title="{{ __('Cancel') }}"><x-fx.icon name="x" /></button>
                                            </form>
                                        @else
                                            <a href="{{ $link }}" class="fx-name">{{ $child->objectable->name }}</a>
                                            <span class="fx-line2">
                                                @if ($isFolder)
                                                    {{ __('Folder') }}, {{ trans_choice(':count record|:count records', $this->folderCounts[$child->id] ?? 0, ['count' => $this->folderCounts[$child->id] ?? 0]) }}
                                                @else
                                                    {{ $file->kindLabel() }}@if ($file->infodot_reference), {{ $file->infodot_reference }}@endif@if ((int) $file->current_version > 1), {{ __('version :n', ['n' => $file->current_version]) }}@endif@if ($this->uploaderNames[$file->uploaded_by] ?? null) {{ __('by') }} {{ $this->uploaderNames[$file->uploaded_by] }}@endif@if ($file->originLabel()), {{ __('from') }} {{ $file->originLabel() }}@endif
                                                @endif
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="fx-c-size fx-num fx-soft">{{ $file ? $file->sizeForHumans() : '—' }}</td>
                            <td class="fx-c-filed fx-soft">{{ $child->created_at->format('j M Y') }}</td>
                            @if ($inCompany)
                                <td class="fx-c-out">
                                    @if ($isFolder)
                                        {{-- a folder is never shared: nothing to say --}}
                                    @else
                                        @if ($out && $out['active'] > 0)
                                            <span class="fx-stamp fx-stamp--active">{{ __('Shared with :count', ['count' => $out['active']]) }}</span>
                                        @endif
                                        @if ($out && $out['pending'] > 0)
                                            <span class="fx-stamp fx-stamp--pending">{{ __('Waiting for approval') }}</span>
                                        @endif
                                        @if (! $out || ($out['active'] === 0 && $out['pending'] === 0))
                                            <span class="fx-soft">{{ __('Not shared') }}</span>
                                        @endif
                                    @endif
                                </td>
                            @endif
                            <td class="fx-c-act">@include('livewire.partials.row-menu')</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
                </div>
            </section>

        <aside class="fx-detail" aria-label="{{ __('Selected record and the room') }}">
            <div class="fx-detail-body" wire:key="fx-detail-{{ $focus ? $focus['obj']->id : 'room' }}" wire:loading.class.delay="is-loading" wire:target="focus">
                @if ($focusNotice)
                    <p class="fx-note" role="status">{{ $focusNotice }}</p>
                @endif

                @if ($focus)
                    @php
                        $f = $focus['file'];
                        $out = $focus['outside'];
                    @endphp
                    <div class="fx-record">
                        <h2 class="fx-rec-title">{{ $f->name }}</h2>
                        <div class="fx-stamps">
                            @if ($focus['pinned'])<span class="fx-stamp fx-stamp--ended">{{ __('Pinned') }}</span>@endif
                            @if ($focus['flagged'])<span class="fx-stamp fx-stamp--pending">{{ __('Flagged for review') }}</span>@endif
                            @if ($out && $out['active'] > 0)<span class="fx-stamp fx-stamp--active">{{ __('Shared with :count', ['count' => $out['active']]) }}</span>@endif
                            @if ($out && $out['pending'] > 0)<span class="fx-stamp fx-stamp--pending">{{ __('Waiting for approval') }}</span>@endif
                        </div>

                        <div class="fx-actions">
                            <a class="fx-btn fx-btn--outline" href="{{ route('files.open', $f->uuid) }}"><x-fx.icon name="open" /> {{ __('Open') }}</a>
                            <a class="fx-btn fx-btn--outline" href="{{ route('files.download', $f) }}"><x-fx.icon name="download" /> {{ __('Download') }}</a>
                            <button type="button" class="fx-btn fx-btn--quiet" wire:click="togglePin({{ $f->id }})">
                                <x-fx.icon name="pin" /> {{ $focus['pinned'] ? __('Unpin') : __('Pin to the front') }}
                            </button>
                            @if ($canShare)
                                <a class="fx-btn fx-btn--quiet" href="{{ route('files.share', $f) }}"><x-fx.icon name="share" /> {{ __('Share outside the company') }}</a>
                            @elseif ($canRequest)
                                <a class="fx-btn fx-btn--quiet" href="{{ route('files.share', $f) }}"><x-fx.icon name="share" /> {{ __('Ask to share') }}</a>
                            @endif
                            <button type="button" class="fx-btn fx-btn--quiet fx-btn--danger-quiet fx-push" wire:click="$set('confirmingObjectDeletion', {{ $focus['obj']->id }})">{{ __('Delete') }}</button>
                        </div>

                        <div class="fx-snap">
                            @if ($f->hasPreview())
                                <img src="{{ route('files.preview', $f) }}" alt="{{ __('First page of :name', ['name' => $f->name]) }}" width="400" height="160" decoding="async">
                            @else
                                <x-fx.icon :name="$f->kind()" class="fx-snap-ico" />
                            @endif
                        </div>

                        <dl class="fx-rows">
                            <div><dt>{{ __('Kind') }}</dt><dd>{{ $f->kindLabel() }}</dd></div>
                            <div><dt>{{ __('Size') }}</dt><dd>{{ $f->sizeForHumans() }}</dd></div>
                            <div><dt>{{ __('Filed') }}</dt><dd>{{ $focus['obj']->created_at->format('j M Y') }}@if ($focus['uploader']) {{ __('by') }} {{ $focus['uploader'] }}@endif</dd></div>
                            @if ($focus['folder'])<div><dt>{{ __('Folder') }}</dt><dd>{{ $focus['folder'] }}</dd></div>@endif
                            <div><dt>{{ __('Version') }}</dt><dd>{{ (int) $f->current_version ?: 1 }}, {{ __('current') }}</dd></div>
                            @if ($f->infodot_reference)<div><dt>{{ __('Reference') }}</dt><dd><span class="fx-ref">{{ $f->infodot_reference }}</span></dd></div>@endif
                            @if ($inCompany)
                                <div><dt>{{ __('Outside') }}</dt><dd>
                                    @if ($out && $out['active'] > 0)
                                        {{ trans_choice('Shared with :count person|Shared with :count people', $out['active'], ['count' => $out['active']]) }}@if ($out['soonest']), {{ __('the first ends :when', ['when' => $out['soonest']->diffForHumans()]) }}@endif
                                    @elseif ($out && $out['pending'] > 0)
                                        <span class="fx-soft">{{ __('A request is waiting for approval') }}</span>
                                    @else
                                        <span class="fx-soft">{{ __('Not shared') }}</span>
                                    @endif
                                </dd></div>
                            @endif
                        </dl>

                        <h3 class="fx-h3">{{ __('Recent versions') }}</h3>
                        <ul class="fx-vers">
                            @foreach ($focus['versions'] as $v)
                                <li>
                                    <span class="fx-ref">v{{ $v->version }}</span>
                                    <span>{{ $v->actionLabel() }}<span class="fx-soft">@if ($v->author) {{ __('by') }} {{ $v->author->name }}@endif, {{ $v->created_at->diffForHumans() }}</span></span>
                                    @if ($v->version === (int) $f->current_version)
                                        <span class="fx-stamp fx-stamp--active">{{ __('Current') }}</span>
                                    @else
                                        <a class="fx-btn fx-btn--quiet" href="{{ route('files.versions.download', [$f, $v]) }}">{{ __('Download') }}</a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        <p><a class="fx-quiet-link" href="{{ route('files.open', $f->uuid) }}">{{ __('Open the full version history') }}</a></p>
                        <p class="fx-back"><button type="button" class="fx-btn fx-btn--quiet" wire:click="clearFocus">{{ __('Back to the room') }}</button></p>
                    </div>
                @else
                    @php $room = $this->room; @endphp
                    <div class="fx-room">
                        @if ($room['pending']->isNotEmpty() || $room['flags']->isNotEmpty())
                            <section class="fx-sec" aria-labelledby="fx-h-wait">
                                <h3 class="fx-h3" id="fx-h-wait">{{ __('Waiting for you') }}</h3>
                                <ul class="fx-list">
                                    @if ($room['pending']->isNotEmpty())
                                        <li><a href="{{ route('approvals') }}">
                                            <span>
                                                <span class="fx-t">{{ $canShare ? trans_choice(':count share request|:count share requests', $room['pending']->count(), ['count' => $room['pending']->count()]) : trans_choice(':count of your requests|:count of your requests', $room['pending']->count(), ['count' => $room['pending']->count()]) }}</span>
                                                <span class="fx-s">{{ $room['pending']->pluck('file.name')->filter()->take(2)->implode(', ') }}@if ($room['pending']->count() > 2) {{ __('and :count more', ['count' => $room['pending']->count() - 2]) }}@endif</span>
                                            </span>
                                            <span class="fx-when">{{ __('Sharing approvals') }}</span>
                                        </a></li>
                                    @endif
                                    @if ($room['flags']->isNotEmpty())
                                        @php $flag = $room['flags']->first(); @endphp
                                        <li><a href="{{ route('approvals') }}">
                                            <span>
                                                <span class="fx-t">{{ trans_choice(':count flagged activity|:count flagged activities', $room['flags']->count(), ['count' => $room['flags']->count()]) }}</span>
                                                <span class="fx-s">{{ $flag->ruleLabel() }}: {{ $flag->file?->name }}</span>
                                            </span>
                                            <span class="fx-when">{{ __('Activity to review') }}</span>
                                        </a></li>
                                    @endif
                                </ul>
                            </section>
                        @endif

                        @if ($room['showEnding'])
                            <section class="fx-sec" aria-labelledby="fx-h-end">
                                <h3 class="fx-h3" id="fx-h-end">{{ __('Ending soon') }}</h3>
                                @if ($room['ending']->isEmpty())
                                    <p class="fx-empty-line">{{ __('Nothing ends in the next :days days.', ['days' => \App\Http\Livewire\FileBrowser::ENDING_DAYS]) }}</p>
                                @else
                                    <ul class="fx-list">
                                        @foreach ($room['ending'] as $share)
                                            @php $days = max(1, (int) ceil(now()->diffInHours($share->expires_at) / 24)); @endphp
                                            <li><a href="{{ route('approvals') }}">
                                                <span>
                                                    <span class="fx-t">{{ $share->file?->name }}</span>
                                                    <span class="fx-s">@if ($canShare){{ $share->email }}, @endif{{ strtolower($share->permissionLabel()) }}</span>
                                                </span>
                                                <span class="fx-when @if ($days <= \App\Http\Livewire\FileBrowser::HEADLINE_DAYS) fx-warn @endif">{{ trans_choice(':count day|:count days', $days, ['count' => $days]) }}</span>
                                            </a></li>
                                        @endforeach
                                    </ul>
                                @endif
                            </section>
                        @endif

                        <section class="fx-sec" aria-labelledby="fx-h-rec">
                            <h3 class="fx-h3" id="fx-h-rec">{{ __('Recently changed') }}</h3>
                            @if ($room['recent']->isEmpty())
                                <p class="fx-empty-line">{{ __('Nothing has changed yet.') }}</p>
                            @else
                                <ul class="fx-list">
                                    @foreach ($room['recent'] as $v)
                                        <li><a href="{{ $v->file ? route('files.open', $v->file->uuid) : '#' }}">
                                            @if ($v->file?->hasPreview())
                                                <img src="{{ route('files.preview', $v->file) }}" alt="" class="fx-thumb" width="30" height="38" loading="lazy" decoding="async">
                                            @endif
                                            <span>
                                                <span class="fx-t">{{ $v->file?->name }}</span>
                                                <span class="fx-s">{{ $v->actionLabel() }}@if ($v->author) {{ __('by') }} {{ $v->author->name }}@endif</span>
                                            </span>
                                            <span class="fx-when">{{ $v->created_at->diffForHumans(short: true) }}</span>
                                        </a></li>
                                    @endforeach
                                </ul>
                            @endif
                        </section>

                        @if ($room['pins']->isNotEmpty())
                            <section class="fx-sec" aria-labelledby="fx-h-pin">
                                <h3 class="fx-h3" id="fx-h-pin">{{ __('Pinned by you') }}</h3>
                                <ul class="fx-list">
                                    @foreach ($room['pins'] as $pin)
                                        <li><a href="{{ route('files.open', $pin->uuid) }}">
                                            <span><span class="fx-t">{{ $pin->name }}</span></span>
                                            <span class="fx-when">{{ $pin->kindLabel() }}</span>
                                        </a></li>
                                    @endforeach
                                </ul>
                            </section>
                        @endif
                    </div>
                @endif
            </div>
        </aside>
        </div>
    @endif
    </div>

    {{-- The foot: the company's real tallies and the isolation sentence, one quiet line (R-51). --}}
    <footer class="fx-foot" aria-label="{{ __('Totals') }}">
        <div class="fx-foot-row">
            @unless ($firstRun)
                <span><b data-tally="documents" data-countup style="--w: {{ strlen(number_format($stats['documents'])) }}ch">{{ number_format($stats['documents']) }}</b> {{ trans_choice('document|documents', $stats['documents']) }}</span>
                <span><b data-tally="folders" data-countup style="--w: {{ strlen(number_format($stats['folders'])) }}ch">{{ number_format($stats['folders']) }}</b> {{ trans_choice('folder|folders', $stats['folders']) }}</span>
                <span><b data-tally="bytes">{{ \App\Models\File::bytesForHumans($stats['bytes']) }}</b> {{ __('on file') }}</span>
                @if ($inCompany)
                    <span><b class="@if ($stats['shared'] > 0) fx-hue @endif" data-tally="shared" data-countup style="--w: {{ strlen(number_format($stats['shared'])) }}ch">{{ number_format($stats['shared']) }}</b> {{ __('shared outside') }}</span>
                @endif
                <span><a href="{{ route('trash') }}"><b>{{ number_format($this->trashedCount) }}</b> {{ __('in the trash') }}</a></span>
            @endunless
            <span class="fx-own">{{ __('Dot.Files — every file here belongs to') }} {{ $team?->name ?? __('your company') }} {{ __('and is visible to no other business.') }}</span>
        </div>
    </footer>

    {{-- Delete confirmation --}}
    @if ($confirmingObjectDeletion)
        <div class="fixed inset-0 z-50 grid place-items-center p-4" role="dialog" aria-modal="true" aria-label="{{ __('Confirm deletion') }}">
            <button type="button" class="absolute inset-0 w-full h-full" style="background: rgba(16, 17, 20, 0.55);"
                    wire:click="$set('confirmingObjectDeletion', null)" aria-label="{{ __('Cancel') }}" tabindex="-1"></button>
            <div class="fx-panel relative w-full max-w-md p-5">
                <h2 class="font-bold text-lg">{{ __('Delete this item?') }}</h2>
                <p class="mt-2 text-sm" style="color: var(--ink-soft);">
                    {{ __('A file or an empty folder is removed at once, and anyone it was shared with loses access. A folder that still holds records goes to a platform operator for approval first.') }}
                </p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" class="fx-btn" wire:click="$set('confirmingObjectDeletion', null)" x-init="$el.focus()">{{ __('Keep it') }}</button>
                    <button type="button" class="fx-btn fx-btn--danger" wire:click="deleteObject" wire:loading.attr="disabled">{{ __('Delete') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Upload tray (always in the DOM so FilePond initialises once) --}}
    <div x-data="{ show: $wire.entangle('showingObjectloadForm') }" x-cloak x-show="show"
         class="fixed inset-0 z-50 grid place-items-center p-4" role="dialog" aria-modal="true" aria-label="{{ __('Upload documents') }}"
         @keydown.escape.window="show = false">
        <button type="button" class="absolute inset-0 w-full h-full" style="background: rgba(16, 17, 20, 0.55);"
                @click="show = false" aria-label="{{ __('Close') }}" tabindex="-1"></button>
        <div class="fx-panel relative w-full max-w-lg p-5">
            <h2 class="font-bold text-lg">{{ __('File documents into') }} {{ $this->query ? $team?->name : ($ancestors->last()?->objectable?->name ?? $team?->name) }}</h2>
            <p class="mt-1 text-sm" style="color: var(--ink-soft);">
                {{ __('Up to 100 MB each — documents, spreadsheets, images, archives and media. Each one is filed for this company only.') }}
            </p>
            <div class="mt-4"
                 wire:ignore
                 x-data="{
                    initFilepond () {
                        const pond = FilePond.create(this.$refs.filepond, {
                            dropOnPage: true,
                            dropValidation: false,
                            onprocessfile: (error, file) => {
                                pond.removeFile(file.id)

                                if (pond.getFiles().length === 0) {
                                    @this.set('showingObjectloadForm', false)
                                }
                            },
                            allowRevert: false,
                            labelIdle: '{{ __('Drop documents here or <span class=&quot;filepond--label-action&quot;>browse</span>') }}',
                            server: {
                                process: (fieldName, file, metadata, load, error, progress, abort, transfer, options) => {
                                    @this.upload('upload', file, load, error, progress)
                                }
                            }
                        })
                    }
                 }"
                 x-init="initFilepond">
                <div class="fx-drop p-1">
                    <input id="filepond" type="file" x-ref="filepond" multiple>
                </div>
            </div>
            @error('upload')<p class="mt-2 text-sm" style="color: var(--danger);">{{ $message }}</p>@enderror
            <div class="mt-4 flex justify-end">
                <button type="button" class="fx-btn" @click="show = false">{{ __('Done') }}</button>
            </div>
        </div>
    </div>
</div>
