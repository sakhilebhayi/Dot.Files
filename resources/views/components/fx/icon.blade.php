@props(['name'])
{{-- The Dot.Files icon set: hand-authored, one stroke weight, round caps (DESIGN.md). --}}
<svg {{ $attributes->merge(['class' => 'w-[18px] h-[18px] shrink-0']) }} viewBox="0 0 24 24" fill="none"
     stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('folder')
            <path d="M3.5 7a2 2 0 0 1 2-2h4l2 2.5h7a2 2 0 0 1 2 2V17a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2V7Z"/>
            @break
        @case('folder-open')
            <path d="M3.5 7a2 2 0 0 1 2-2h4l2 2.5h7a2 2 0 0 1 2 2V11"/>
            <path d="M2.8 12.2h16.4l-1.6 6.8h-13L2.8 12.2Z"/>
            @break
        @case('doc')
            <path d="M6 3.5h8l4 4V19a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 6 19V3.5Z"/>
            <path d="M14 3.5V8h4"/>
            <path d="M9 12h6M9 15h6"/>
            @break
        @case('pdf')
            <path d="M6 3.5h8l4 4V19a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 6 19V3.5Z"/>
            <path d="M14 3.5V8h4"/>
            <path d="M9 15.5v-4h1.6a1.3 1.3 0 0 1 0 2.6H9M14.5 15.5v-4l2 4v-4"/>
            @break
        @case('sheet')
            <path d="M6 3.5h8l4 4V19a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 6 19V3.5Z"/>
            <path d="M14 3.5V8h4"/>
            <path d="M9 12h6M9 15h6M12 12v5.5"/>
            @break
        @case('image')
            <rect x="4" y="5" width="16" height="14" rx="1.5"/>
            <circle cx="9" cy="10" r="1.4"/>
            <path d="M4.5 17.5 10 12l3.5 3.5 2.5-2.5 3.5 3.5"/>
            @break
        @case('media')
            <rect x="4" y="5" width="16" height="14" rx="1.5"/>
            <path d="m10.5 9.5 4.5 2.5-4.5 2.5v-5Z"/>
            @break
        @case('audio')
            <path d="M9 17.5V7l9-2v10.5"/>
            <circle cx="7" cy="17.5" r="2"/>
            <circle cx="16" cy="15.5" r="2"/>
            @break
        @case('archive')
            <rect x="4" y="4.5" width="16" height="4.5" rx="1"/>
            <path d="M5.5 9v9A1.5 1.5 0 0 0 7 19.5h10a1.5 1.5 0 0 0 1.5-1.5V9M10 12.5h4"/>
            @break
        @case('file')
            <path d="M6 3.5h8l4 4V19a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 6 19V3.5Z"/>
            <path d="M14 3.5V8h4"/>
            @break
        @case('search')
            <circle cx="10.5" cy="10.5" r="6"/>
            <path d="m15.5 15.5 4.5 4.5"/>
            @break
        @case('grid')
            <rect x="4" y="4" width="7" height="7" rx="1.2"/>
            <rect x="13" y="4" width="7" height="7" rx="1.2"/>
            <rect x="4" y="13" width="7" height="7" rx="1.2"/>
            <rect x="13" y="13" width="7" height="7" rx="1.2"/>
            @break
        @case('list')
            <path d="M8.5 6.5H20M8.5 12H20M8.5 17.5H20"/>
            <path d="M4.2 6.5h.1M4.2 12h.1M4.2 17.5h.1" stroke-width="2.4"/>
            @break
        @case('plus')
            <path d="M12 5v14M5 12h14"/>
            @break
        @case('check')
            <path d="m5 12.5 4.5 4.5L19 7.5"/>
            @break
        @case('x')
            <path d="M6 6l12 12M18 6 6 18"/>
            @break
        @case('dots')
            <path d="M12 5.4h.1M12 12h.1M12 18.6h.1" stroke-width="2.6"/>
            @break
        @case('download')
            <path d="M12 4v10m0 0 4-4m-4 4-4-4"/>
            <path d="M4.5 16.5V18A1.5 1.5 0 0 0 6 19.5h12a1.5 1.5 0 0 0 1.5-1.5v-1.5"/>
            @break
        @case('upload')
            <path d="M12 14V4m0 0 4 4m-4-4-4 4"/>
            <path d="M4.5 16.5V18A1.5 1.5 0 0 0 6 19.5h12a1.5 1.5 0 0 0 1.5-1.5v-1.5"/>
            @break
        @case('share')
            <circle cx="6.5" cy="12" r="2.3"/>
            <circle cx="17" cy="6" r="2.3"/>
            <circle cx="17" cy="18" r="2.3"/>
            <path d="m8.6 10.9 6.3-3.7M8.6 13.1l6.3 3.7"/>
            @break
        @case('clock')
            <circle cx="12" cy="12" r="8"/>
            <path d="M12 7.5V12l3 2"/>
            @break
        @case('shield')
            <path d="M12 3.5 5 6v5.5c0 4.3 3 7.7 7 9 4-1.3 7-4.7 7-9V6l-7-2.5Z"/>
            <path d="m9 12 2.2 2.2L15.5 9.8"/>
            @break
        @case('rename')
            <path d="m14.5 5.5 4 4L8 20H4v-4L14.5 5.5Z"/>
            <path d="m12.5 7.5 4 4"/>
            @break
        @case('open')
            <path d="M10 5H6a1.5 1.5 0 0 0-1.5 1.5V18A1.5 1.5 0 0 0 6 19.5h11.5A1.5 1.5 0 0 0 19 18v-4"/>
            <path d="M14 4.5h5.5V10M19.2 4.8 11.5 12.5"/>
            @break
        @case('pin')
            <path d="M9 3.5h6l-1 6 3.5 3.5v1.5h-11V13L10 9.5l-1-6Z"/>
            <path d="M12 14.5V21"/>
            @break
        @case('stamp')
            <path d="M9.5 11.5V7a2.5 2.5 0 0 1 5 0v4.5"/>
            <path d="M5.5 15.5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1h-13v-1ZM5.5 19.5h13"/>
            @break
        @default
            <path d="M6 3.5h8l4 4V19a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 6 19V3.5Z"/>
            <path d="M14 3.5V8h4"/>
    @endswitch
</svg>
