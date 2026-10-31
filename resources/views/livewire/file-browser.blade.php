@php
    $team = $this->currentTeam;
    $user = auth()->user();
    $canShare = $team && ! $team->personal_team && ($user->ownsTeam($team) || $user->hasTeamRole($team, 'admin'));
    $canRequest = $team && ! $team->personal_team && ! $canShare;
    $stats = $this->stats;
    $pinnedIds = \App\Models\FilePin::where('user_id', $user->id)->pluck('file_id')->all();
@endphp

<div class="space-y-4"
     @keydown.slash.window="if (!['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) { $event.preventDefault(); document.getElementById('fx-search')?.focus() }"
     @dragenter.window="if (Array.from($event.dataTransfer?.types ?? []).includes('Files')) $wire.showingObjectloadForm = true">

    {{-- The room head: this company's identity and its real tallies. --}}
    <section class="fx-roomhead" aria-label="{{ __('Company records summary') }}">
        <div class="flex items-center gap-3 min-w-0">
            <span class="fx-initial" aria-hidden="true">{{ mb_strtoupper(mb_substr($team?->name ?? 'D', 0, 1)) }}</span>
            <div class="min-w-0">
                <h1 class="text-lg font-bold leading-tight truncate">{{ $team?->name }}</h1>
                <p class="text-sm" style="color: var(--ink-soft);">{{ __('The company archive — every record here is yours alone.') }}</p>
            </div>
        </div>

        <div class="flex items-center gap-6 ml-auto">
            <div>
                <p class="fx-tally">{{ number_format($stats['documents']) }}</p>
                <p class="fx-tally-label">{{ trans_choice('document|documents', $stats['documents']) }}</p>
            </div>
            <div>
                <p class="fx-tally">{{ number_format($stats['folders']) }}</p>
                <p class="fx-tally-label">{{ trans_choice('folder|folders', $stats['folders']) }}</p>
            </div>
            <div>
                <p class="fx-tally">{{ \App\Models\File::bytesForHumans($stats['bytes']) }}</p>
                <p class="fx-tally-label">{{ __('on file') }}</p>
            </div>
        </div>

        @if ($this->pendingShares > 0)
            <a href="{{ route('approvals') }}" class="fx-stamp fx-stamp--pending" style="text-decoration: none;">
                {{ trans_choice(':count share request waits on you|:count share requests wait on you', $this->pendingShares, ['count' => $this->pendingShares]) }}
            </a>
        @endif
    </section>

    {{-- Pinned to the front: this person's own shortlist, shown while browsing the root. --}}
    @if (! $this->query && $this->object->parent_id === null && $this->pins->isNotEmpty())
        <section aria-label="{{ __('Pinned') }}" class="flex flex-wrap items-center gap-2">
            <span class="text-sm font-medium" style="color: var(--ink-soft);">{{ __('Pinned') }}</span>
            @foreach ($this->pins as $pin)
                <span class="fx-panel inline-flex items-center gap-1.5 !rounded-md pl-2.5 pr-1 py-1">
                    <x-fx.icon :name="$pin->kind()" class="w-4 h-4" style="color: var(--oxide);" />
                    <a href="{{ route('files.open', $pin->uuid) }}" class="text-sm font-medium max-w-[14rem] truncate">{{ $pin->name }}</a>
                    <button type="button" class="fx-btn fx-btn--quiet !min-h-[26px] !px-1" wire:click="togglePin({{ $pin->id }})"
                            title="{{ __('Unpin') }}" aria-label="{{ __('Unpin') }} {{ $pin->name }}">
                        <x-fx.icon name="x" class="w-3.5 h-3.5" />
                    </button>
                </span>
            @endforeach
        </section>
    @endif

    {{-- Toolbar --}}
    <div class="flex flex-wrap items-center gap-2">
        <label class="relative flex-grow min-w-[14rem]">
            <span class="sr-only">{{ __('Search this company’s records') }}</span>
            <span class="absolute left-3 top-1/2 -translate-y-1/2" style="color: var(--ink-faint);">
                <x-fx.icon name="search" />
            </span>
            <input id="fx-search" type="search" placeholder="{{ __('Search this company’s records — press /') }}"
                   class="fx-input pl-10" wire:model.live.debounce.300ms="query">
        </label>

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

        <div class="flex items-center rounded-md border" style="border-color: var(--line-strong);" role="group" aria-label="{{ __('View') }}">
            <button type="button" wire:click="setView('grid')" class="fx-btn fx-btn--quiet !min-h-[42px] !rounded-r-none"
                    @if ($view === 'grid') style="background: var(--oxide-wash); color: var(--oxide);" aria-pressed="true" @else aria-pressed="false" @endif
                    title="{{ __('Shelf view') }}">
                <x-fx.icon name="grid" /><span class="sr-only">{{ __('Shelf view') }}</span>
            </button>
            <button type="button" wire:click="setView('list')" class="fx-btn fx-btn--quiet !min-h-[42px] !rounded-l-none"
                    @if ($view === 'list') style="background: var(--oxide-wash); color: var(--oxide);" aria-pressed="true" @else aria-pressed="false" @endif
                    title="{{ __('Register view') }}">
                <x-fx.icon name="list" /><span class="sr-only">{{ __('Register view') }}</span>
            </button>
        </div>

        <button type="button" class="fx-btn" wire:click="$set('creatingNewFolder', true)">
            <x-fx.icon name="folder" /> {{ __('New folder') }}
        </button>
        <button type="button" class="fx-btn fx-btn--oxide" wire:click="$set('showingObjectloadForm', true)">
            <x-fx.icon name="upload" /> {{ __('Upload documents') }}
        </button>
    </div>

    @if ($bulkDeletionRequested)
        <div class="fx-note" role="status">
            {{ __('Deletion requested. A platform operator checks every bulk removal before anything is taken off the record — nothing is gone yet.') }}
        </div>
    @endif

    @if ($creatingNewFolder)
        <form class="fx-panel flex flex-wrap items-center gap-2 p-3" wire:submit.prevent="createFolder">
            <x-fx.icon name="folder" style="color: var(--ink-soft);" />
            <label for="new-folder-name" class="sr-only">{{ __('Folder name') }}</label>
            <input id="new-folder-name" type="text" class="fx-input flex-grow min-w-[12rem]" placeholder="{{ __('Folder name') }}"
                   wire:model="newFolderState.name" x-init="$el.focus()">
            <button type="submit" class="fx-btn fx-btn--oxide">{{ __('Create folder') }}</button>
            <button type="button" class="fx-btn" wire:click="$set('creatingNewFolder', false)">{{ __('Cancel') }}</button>
            @error('newFolderState.name')<p class="w-full text-sm" style="color: var(--oxide);">{{ $message }}</p>@enderror
        </form>
    @endif

    {{-- Path / search strip + selection actions --}}
    <div class="flex flex-wrap items-center gap-2">
        <nav class="fx-crumbs flex-grow" aria-label="{{ __('Folder path') }}">
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

        @if (count($selectedObjects) > 0)
            <button type="button" class="fx-btn" style="color: var(--oxide); border-color: var(--oxide);"
                    wire:click="requestBulkDeletion">
                <x-fx.icon name="x" />
                {{ trans_choice('Request deletion of :count item|Request deletion of :count items', count($selectedObjects), ['count' => count($selectedObjects)]) }}
            </button>
        @endif
    </div>

    {{-- The records --}}
    @if ($this->results->count() === 0)
        <div class="fx-panel fx-empty">
            <x-fx.icon name="folder-open" class="w-10 h-10" style="color: var(--ink-faint);" />
            @if ($this->query)
                <p class="font-semibold">{{ __('No records match') }} “{{ $this->query }}”.</p>
                <p class="text-sm">{{ __('Try fewer words, or look through the folders — the search covers names and folder paths.') }}</p>
                <button type="button" class="fx-btn" wire:click="$set('query', null)">{{ __('Clear search') }}</button>
            @else
                <p class="font-semibold">{{ __('Nothing is filed here yet.') }}</p>
                <p class="text-sm">{{ __('Documents you upload appear on this shelf, snapshot first, and only your company can see them.') }}</p>
                <button type="button" class="fx-btn fx-btn--oxide" wire:click="$set('showingObjectloadForm', true)">
                    <x-fx.icon name="upload" /> {{ __('Upload the first document') }}
                </button>
            @endif
        </div>
    @elseif ($view === 'grid')
        <ul class="fx-shelf" role="list">
            @foreach ($this->results as $child)
                <li class="fx-card @if ($child->objectable_type === 'folder') fx-folder @endif" wire:key="card-{{ $child->id }}">
                    <label class="fx-card-check absolute top-2 left-2 z-10 p-1.5 rounded" style="background: color-mix(in srgb, var(--panel) 75%, transparent);">
                        <input type="checkbox" class="fx-check" wire:model="selectedObjects" value="{{ $child->id }}"
                               aria-label="{{ __('Select') }} {{ $child->objectable->name }}">
                    </label>

                    @if ($child->objectable_type === 'folder')
                        <a href="{{ route('files', ['uuid' => $child->uuid]) }}" class="fx-card-face" tabindex="-1" aria-hidden="true">
                            <x-fx.icon name="folder" class="w-9 h-9" />
                        </a>
                    @elseif ($child->objectable->hasPreview())
                        <a href="{{ route('files.open', $child->objectable->uuid) }}" class="fx-card-face" tabindex="-1" aria-hidden="true">
                            <img src="{{ route('files.preview', $child->objectable) }}" alt="" loading="lazy">
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
                                <a href="{{ route('files', ['uuid' => $child->uuid]) }}" class="fx-card-title">{{ $child->objectable->name }}</a>
                                <p class="fx-card-meta">{{ __('Folder') }} · {{ $child->created_at->format('j M Y') }}</p>
                            @else
                                <a href="{{ route('files.open', $child->objectable->uuid) }}" class="fx-card-title">{{ $child->objectable->name }}</a>
                                <p class="fx-card-meta">{{ $child->objectable->kindLabel() }} · {{ $child->objectable->sizeForHumans() }} · {{ $child->created_at->format('j M Y') }}</p>
                            @endif
                        @endif

                        <div class="flex items-center justify-between gap-2 mt-1 min-w-0">
                            @if ($child->objectable_type === 'file' && $child->objectable->infodot_reference)
                                <span class="fx-ref truncate" title="{{ __('InfoDot reference — paste it into InfoDot search to open this file') }}">{{ $child->objectable->infodot_reference }}</span>
                            @else
                                <span></span>
                            @endif

                            <div class="relative" x-data="{ m: false }" @keydown.escape.stop="m = false">
                                <button type="button" class="fx-btn fx-btn--quiet !min-h-[32px] !px-1.5" @click="m = ! m"
                                        :aria-expanded="m.toString()" aria-haspopup="menu" aria-label="{{ __('Actions for') }} {{ $child->objectable->name }}">
                                    <x-fx.icon name="dots" />
                                </button>
                                <div x-cloak x-show="m" @click.outside="m = false" class="fx-menu absolute right-0 bottom-full mb-1" role="menu">
                                    @if ($child->objectable_type === 'file')
                                        <a href="{{ route('files.open', $child->objectable->uuid) }}" role="menuitem"><x-fx.icon name="open" /> {{ __('Open') }}</a>
                                        <a href="{{ route('files.download', $child->objectable) }}" role="menuitem"><x-fx.icon name="download" /> {{ __('Download') }}</a>
                                        <button type="button" role="menuitem" wire:click="togglePin({{ $child->objectable->id }})" @click="m = false">
                                            <x-fx.icon name="pin" /> {{ in_array($child->objectable->id, $pinnedIds) ? __('Unpin') : __('Pin to the front') }}
                                        </button>
                                        @if ($canShare)
                                            <a href="{{ route('files.share', $child->objectable) }}" role="menuitem"><x-fx.icon name="share" /> {{ __('Share outside the company') }}</a>
                                        @elseif ($canRequest)
                                            <a href="{{ route('files.share', $child->objectable) }}" role="menuitem"><x-fx.icon name="share" /> {{ __('Ask to share') }}</a>
                                        @endif
                                    @endif
                                    <button type="button" role="menuitem" wire:click="$set('renamingObject', {{ $child->id }})" @click="m = false">
                                        <x-fx.icon name="rename" /> {{ __('Rename') }}
                                    </button>
                                    <button type="button" role="menuitem" class="fx-menu-danger" wire:click="$set('confirmingObjectDeletion', {{ $child->id }})" @click="m = false">
                                        <x-fx.icon name="x" /> {{ __('Delete') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @else
        <div class="fx-panel overflow-x-auto">
            <table class="fx-register">
                <thead>
                    <tr>
                        <th class="w-10"><span class="sr-only">{{ __('Select') }}</span></th>
                        <th>{{ __('Record') }}</th>
                        <th class="hidden md:table-cell">{{ __('Reference') }}</th>
                        <th class="hidden sm:table-cell">{{ __('Kind') }}</th>
                        <th>{{ __('Size') }}</th>
                        <th class="hidden sm:table-cell">{{ __('Filed') }}</th>
                        <th class="w-12"><span class="sr-only">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->results as $child)
                        <tr wire:key="row-{{ $child->id }}">
                            <td>
                                <input type="checkbox" class="fx-check" wire:model="selectedObjects" value="{{ $child->id }}"
                                       aria-label="{{ __('Select') }} {{ $child->objectable->name }}">
                            </td>
                            <td>
                                <div class="flex items-center gap-2.5 min-w-0">
                                    <span style="color: var(--ink-soft);">
                                        <x-fx.icon :name="$child->objectable_type === 'folder' ? 'folder' : $child->objectable->kind()" />
                                    </span>
                                    @if ($renamingObject === $child->id)
                                        <form class="flex items-center gap-1.5 flex-grow" wire:submit.prevent="renameObject">
                                            <label class="sr-only" for="rename-row-{{ $child->id }}">{{ __('New name') }}</label>
                                            <input id="rename-row-{{ $child->id }}" type="text" class="fx-input !min-h-[36px] text-sm" wire:model="renamingObjectState.name" x-init="$el.focus()">
                                            <button type="submit" class="fx-btn fx-btn--quiet" title="{{ __('Save name') }}"><x-fx.icon name="check" /></button>
                                            <button type="button" class="fx-btn fx-btn--quiet" wire:click="$set('renamingObject', null)" title="{{ __('Cancel') }}"><x-fx.icon name="x" /></button>
                                        </form>
                                    @elseif ($child->objectable_type === 'folder')
                                        <a href="{{ route('files', ['uuid' => $child->uuid]) }}" class="font-semibold break-words">{{ $child->objectable->name }}</a>
                                    @else
                                        <a href="{{ route('files.open', $child->objectable->uuid) }}" class="font-semibold break-words">{{ $child->objectable->name }}</a>
                                    @endif
                                </div>
                            </td>
                            <td class="hidden md:table-cell">
                                @if ($child->objectable_type === 'file' && $child->objectable->infodot_reference)
                                    <span class="fx-ref" title="{{ __('InfoDot reference — paste it into InfoDot search to open this file') }}">{{ $child->objectable->infodot_reference }}</span>
                                @endif
                            </td>
                            <td class="hidden sm:table-cell" style="color: var(--ink-soft);">
                                {{ $child->objectable_type === 'folder' ? __('Folder') : $child->objectable->kindLabel() }}
                            </td>
                            <td style="color: var(--ink-soft);">
                                {{ $child->objectable_type === 'file' ? $child->objectable->sizeForHumans() : '—' }}
                            </td>
                            <td class="hidden sm:table-cell" style="color: var(--ink-soft);">{{ $child->created_at->format('j M Y') }}</td>
                            <td>
                                <div class="relative" x-data="{ m: false }" @keydown.escape.stop="m = false">
                                    <button type="button" class="fx-btn fx-btn--quiet !min-h-[32px] !px-1.5" @click="m = ! m"
                                            :aria-expanded="m.toString()" aria-haspopup="menu" aria-label="{{ __('Actions for') }} {{ $child->objectable->name }}">
                                        <x-fx.icon name="dots" />
                                    </button>
                                    <div x-cloak x-show="m" @click.outside="m = false" class="fx-menu absolute right-0 top-full mt-1" role="menu">
                                        @if ($child->objectable_type === 'file')
                                            <a href="{{ route('files.open', $child->objectable->uuid) }}" role="menuitem"><x-fx.icon name="open" /> {{ __('Open') }}</a>
                                            <a href="{{ route('files.download', $child->objectable) }}" role="menuitem"><x-fx.icon name="download" /> {{ __('Download') }}</a>
                                            <button type="button" role="menuitem" wire:click="togglePin({{ $child->objectable->id }})" @click="m = false">
                                                <x-fx.icon name="pin" /> {{ in_array($child->objectable->id, $pinnedIds) ? __('Unpin') : __('Pin to the front') }}
                                            </button>
                                            @if ($canShare)
                                                <a href="{{ route('files.share', $child->objectable) }}" role="menuitem"><x-fx.icon name="share" /> {{ __('Share outside the company') }}</a>
                                            @elseif ($canRequest)
                                                <a href="{{ route('files.share', $child->objectable) }}" role="menuitem"><x-fx.icon name="share" /> {{ __('Ask to share') }}</a>
                                            @endif
                                        @endif
                                        <button type="button" role="menuitem" wire:click="$set('renamingObject', {{ $child->id }})" @click="m = false">
                                            <x-fx.icon name="rename" /> {{ __('Rename') }}
                                        </button>
                                        <button type="button" role="menuitem" class="fx-menu-danger" wire:click="$set('confirmingObjectDeletion', {{ $child->id }})" @click="m = false">
                                            <x-fx.icon name="x" /> {{ __('Delete') }}
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Delete confirmation --}}
    @if ($confirmingObjectDeletion)
        <div class="fixed inset-0 z-50 grid place-items-center p-4" role="dialog" aria-modal="true" aria-label="{{ __('Confirm deletion') }}">
            <button type="button" class="absolute inset-0 w-full h-full" style="background: rgba(23, 20, 15, 0.55);"
                    wire:click="$set('confirmingObjectDeletion', null)" aria-label="{{ __('Cancel') }}" tabindex="-1"></button>
            <div class="fx-panel relative w-full max-w-md p-5">
                <h2 class="font-bold text-lg">{{ __('Take this off the record?') }}</h2>
                <p class="mt-2 text-sm" style="color: var(--ink-soft);">
                    {{ __('A file or an empty folder is removed at once, and anyone it was shared with loses access. A folder that still holds records goes to a platform operator for approval first.') }}
                </p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" class="fx-btn" wire:click="$set('confirmingObjectDeletion', null)">{{ __('Keep it') }}</button>
                    <button type="button" class="fx-btn fx-btn--oxide" wire:click="deleteObject" wire:loading.attr="disabled">{{ __('Delete') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Upload tray (always in the DOM so FilePond initialises once) --}}
    <div x-data="{ show: $wire.entangle('showingObjectloadForm') }" x-cloak x-show="show"
         class="fixed inset-0 z-50 grid place-items-center p-4" role="dialog" aria-modal="true" aria-label="{{ __('Upload documents') }}"
         @keydown.escape.window="show = false">
        <button type="button" class="absolute inset-0 w-full h-full" style="background: rgba(23, 20, 15, 0.55);"
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
            @error('upload')<p class="mt-2 text-sm" style="color: var(--oxide);">{{ $message }}</p>@enderror
            <div class="mt-4 flex justify-end">
                <button type="button" class="fx-btn" @click="show = false">{{ __('Done') }}</button>
            </div>
        </div>
    </div>
</div>
