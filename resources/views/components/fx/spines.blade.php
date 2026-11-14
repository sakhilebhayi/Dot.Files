@props(['spines' => collect(), 'slots' => 14])
{{--
  The arrival's drawing: the company's documents as a row of filing spines, newest first. One spine per real
  document (at most 24), its height from the file's size on a log scale, and the one data hue marks a document that
  is shared outside the company. With nothing filed it draws empty slots in outline, which is all a first run has.
  Decorative only (aria-hidden): every fact it encodes is stated in text on the page.
--}}
@php
    $items = $spines->take(24)->values();
    $empty = $items->isEmpty();
    $n = $empty ? $slots : $items->count();
    $w = 7;
    $gap = 4;
    $h = 56;
    $maxLog = max(1, $empty ? 1 : $items->max(fn ($s) => log(max($s['size'], 1) + 1)));
    $minLog = $empty ? 0 : $items->min(fn ($s) => log(max($s['size'], 1) + 1));
    $width = $n * $w + ($n - 1) * $gap;
@endphp
<svg {{ $attributes->merge(['class' => 'fx-spines']) }} viewBox="0 0 {{ $width }} {{ $h }}" width="{{ $width }}" height="{{ $h }}" aria-hidden="true" focusable="false">
    @for ($i = 0; $i < $n; $i++)
        @php
            $s = $empty ? null : $items[$i];
            $ratio = $empty ? 0.62 : (($maxLog === $minLog) ? 0.75 : 0.45 + 0.55 * ((log(max($s['size'], 1) + 1) - $minLog) / ($maxLog - $minLog)));
            $sh = round($h * $ratio);
            $x = $i * ($w + $gap);
        @endphp
        @if ($empty)
            <rect class="fx-spine fx-spine--slot" style="--i: {{ $i }}" x="{{ $x + 0.5 }}" y="{{ $h - $sh + 0.5 }}" width="{{ $w - 1 }}" height="{{ $sh - 1 }}" rx="1.5" fill="none" stroke="currentColor" stroke-width="1" stroke-dasharray="2 2" opacity=".45"/>
        @else
            <rect class="fx-spine @if ($s['shared']) fx-spine--out @endif" style="--i: {{ $i }}" x="{{ $x }}" y="{{ $h - $sh }}" width="{{ $w }}" height="{{ $sh }}" rx="1.5" fill="{{ $s['shared'] ? 'var(--data)' : 'currentColor' }}" opacity="{{ $s['shared'] ? 1 : 0.88 }}"/>
        @endif
    @endfor
</svg>
