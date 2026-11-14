{{-- The header search: the shared Dot UI pill (<x-dot.search>), identical to InfoDot's, working on this company's files (R-28 §3, R-51). Logic, keys and results are unchanged. --}}
<div x-data>
    <x-dot.search
        name="search"
        :action="route('files')"
        :placeholder="__('Search your files — or paste a reference')"
        :label="__('Search this company’s files and folders, or jump to a reference')"
        hint="Ctrl K"
        wire:model.live.debounce.250ms="query"
        wire:keydown.escape="$set('query', '')"
        wire:keydown.arrow-up.prevent="decrementHighlight"
        wire:keydown.arrow-down.prevent="incrementHighlight"
        wire:keydown.enter="openHighlighted"
        x-on:keydown.enter="if ($wire.query.trim() !== '') $event.preventDefault()">
        <x-slot:results>
            @php($i = 0)
            @if (blank(trim($query)))
                <div class="dsr-quick">
                    @if (count($this->recent))
                        <div class="dsr-head">{{ __('New in') }} {{ auth()->user()?->currentTeam?->name ?? __('your company') }}</div>
                        @foreach ($this->recent as $item)
                            <a class="dsr-item" href="{{ $item['url'] }}" role="option">
                                @if ($item['ref'])<span class="dsr-ref">{{ $item['ref'] }}</span>@endif<span class="dsr-label">{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                    @endif

                    <div class="dsr-head">{{ __('Go to') }}</div>
                    <div class="dsr-chips">
                        <a class="dsr-chip" href="{{ route('files') }}">{{ __('Upload documents') }}</a>
                        <a class="dsr-chip" href="{{ route('approvals') }}">{{ __('Sharing approvals') }}</a>
                        <a class="dsr-chip" href="{{ route('shared') }}">{{ __('Shared with me') }}</a>
                        <a class="dsr-chip" href="{{ route('trash') }}">{{ __('Trash') }}</a>
                    </div>
                    <p class="dsr-foot">{{ __('Type a reference such as') }} <span class="dsr-ref">{{ $this->recent->firstWhere('ref')['ref'] ?? 'MKDL-F-000001' }}</span> {{ __('to open that file.') }}</p>
                </div>
            @else
                @if (! $this->reference && $this->items->isEmpty() && $this->shared->isEmpty())
                    <p class="dsr-foot">{{ __('Nothing matches') }} &ldquo;{{ $query }}&rdquo;. {{ __('Try fewer words — the search covers names and folder paths.') }}</p>
                @endif

                @if ($this->reference)
                    <div class="dsr-head">{{ __('Reference') }}</div>
                    <a class="dsr-item @if ($highlightIndex === $i) is-active @endif" href="{{ route('files.open', $this->reference->uuid) }}" role="option">
                        <span class="dsr-ref">{{ $this->reference->infodot_reference }}</span><span class="dsr-label">{{ $this->reference->name }}</span>
                    </a>
                    @php($i++)
                @endif

                @if ($this->items->isNotEmpty())
                    <div class="dsr-head">{{ __('In') }} {{ auth()->user()?->currentTeam?->name ?? __('your company') }}</div>
                    @foreach ($this->items as $item)
                        <a class="dsr-item @if ($highlightIndex === $i) is-active @endif" href="{{ $item['url'] }}" role="option">
                            <x-fx.icon :name="$item['kind']" class="dsr-icon" /><span class="dsr-label">{{ $item['label'] }}</span>
                            @if ($item['ref'])<span class="dsr-tag">{{ $item['ref'] }}</span>@endif
                        </a>
                        @php($i++)
                    @endforeach
                @endif

                @if ($this->shared->isNotEmpty())
                    <div class="dsr-head">{{ __('Shared with you') }}</div>
                    @foreach ($this->shared as $item)
                        <a class="dsr-item @if ($highlightIndex === $i) is-active @endif" href="{{ $item['url'] }}" role="option">
                            <x-fx.icon :name="$item['kind']" class="dsr-icon" /><span class="dsr-label">{{ $item['label'] }}</span>
                        </a>
                        @php($i++)
                    @endforeach
                @endif
            @endif
        </x-slot:results>
    </x-dot.search>
</div>
