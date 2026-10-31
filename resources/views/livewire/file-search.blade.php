{{-- InfoDot's search bar, exactly, working on this company's files (R-28 §3). --}}
<div class="ix-search"
     x-data="{ open: false }"
     @click.outside="open = false"
     @keydown.window.prevent.ctrl.k="$refs.q.focus()"
     @keydown.window.prevent.meta.k="$refs.q.focus()"
     @keydown.escape="open = false; $refs.q.blur()">
    <label for="ix-search-input" class="ix-sr">{{ __('Search this company’s files and folders, or jump to a reference') }}</label>
    <form action="{{ route('files') }}" method="GET" role="search">
        <input
            id="ix-search-input"
            name="search"
            x-ref="q"
            type="search"
            placeholder="{{ __('Search your files — or paste a reference') }}"
            autocomplete="off"
            class="ix-input"
            role="combobox"
            aria-autocomplete="list"
            aria-controls="ix-search-list"
            :aria-expanded="open"
            @focus="open = true"
            wire:model.live.debounce.250ms="query"
            wire:keydown.escape="$set('query', '')"
            wire:keydown.arrow-up.prevent="decrementHighlight"
            wire:keydown.arrow-down.prevent="incrementHighlight"
            wire:keydown.enter="openHighlighted"
            @keydown.enter="if ($wire.query.trim() !== '') $event.preventDefault()"
        />
        <kbd class="ix-kbd" aria-hidden="true" x-show="!open">Ctrl K</kbd>

        <button type="submit" class="ix-search__go" aria-label="{{ __('Search your files') }}">
            <span class="ix-arrow" aria-hidden="true" style="--ix-arrow-url: url('{{ asset('img/arrow-mask.png') }}')"></span>
        </button>
    </form>

    <div id="ix-search-list" class="ix-results" x-show="open" x-cloak x-transition.opacity.duration.100ms role="listbox" aria-label="{{ __('Suggestions') }}">
        @php($i = 0)
        @if (blank(trim($query)))
            <div class="ix-quick">
                @if (count($this->recent))
                    <div class="ix-pop__head">{{ __('New in') }} {{ auth()->user()?->currentTeam?->name ?? __('your company') }}</div>
                    @foreach ($this->recent as $item)
                        <a class="ix-results__item" href="{{ $item['url'] }}" role="option">
                            @if ($item['ref'])<span class="fx-ref">{{ $item['ref'] }}</span>@endif{{ $item['label'] }}
                        </a>
                    @endforeach
                @endif

                <div class="ix-pop__head">{{ __('Go to') }}</div>
                <div class="ix-quick__row">
                    <a class="ix-quick__chip" href="{{ route('files') }}"><x-fx.icon name="upload" class="w-[18px] h-[18px]" />{{ __('Upload documents') }}</a>
                    <a class="ix-quick__chip" href="{{ route('approvals') }}"><x-fx.icon name="share" class="w-[18px] h-[18px]" />{{ __('Sharing approvals') }}</a>
                    <a class="ix-quick__chip" href="{{ route('shared') }}"><x-fx.icon name="open" class="w-[18px] h-[18px]" />{{ __('Shared with me') }}</a>
                    <a class="ix-quick__chip" href="{{ route('trash') }}"><x-fx.icon name="x" class="w-[18px] h-[18px]" />{{ __('Trash') }}</a>
                </div>
                <p class="ix-results__foot">{{ __('Type a reference such as') }} <span class="fx-ref">{{ $this->recent->firstWhere('ref')['ref'] ?? 'MKDL-F-000001' }}</span> {{ __('to open that file.') }}</p>
            </div>
        @else
            @if (! $this->reference && $this->items->isEmpty() && $this->shared->isEmpty())
                <p class="ix-results__foot">{{ __('Nothing matches') }} &ldquo;{{ $query }}&rdquo;. {{ __('Try fewer words — the search covers names and folder paths.') }}</p>
            @endif

            @if ($this->reference)
                <div class="ix-pop__head">{{ __('Reference') }}</div>
                <a class="ix-results__item @if ($highlightIndex === $i) is-active @endif" href="{{ route('files.open', $this->reference->uuid) }}" role="option">
                    <span class="fx-ref">{{ $this->reference->infodot_reference }}</span>{{ $this->reference->name }}
                </a>
                @php($i++)
            @endif

            @if ($this->items->isNotEmpty())
                <div class="ix-pop__head">{{ __('In') }} {{ auth()->user()?->currentTeam?->name ?? __('your company') }}</div>
                @foreach ($this->items as $item)
                    <a class="ix-results__item @if ($highlightIndex === $i) is-active @endif" href="{{ $item['url'] }}" role="option">
                        <x-fx.icon :name="$item['kind']" class="w-[18px] h-[18px]" />{{ $item['label'] }}
                        @if ($item['ref'])<span class="ix-results__tag">{{ $item['ref'] }}</span>@endif
                    </a>
                    @php($i++)
                @endforeach
            @endif

            @if ($this->shared->isNotEmpty())
                <div class="ix-pop__head">{{ __('Shared with you') }}</div>
                @foreach ($this->shared as $item)
                    <a class="ix-results__item @if ($highlightIndex === $i) is-active @endif" href="{{ $item['url'] }}" role="option">
                        <x-fx.icon :name="$item['kind']" class="w-[18px] h-[18px]" />{{ $item['label'] }}
                    </a>
                    @php($i++)
                @endforeach
            @endif
        @endif
    </div>
</div>
