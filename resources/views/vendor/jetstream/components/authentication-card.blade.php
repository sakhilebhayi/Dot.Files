<div class="relative min-h-screen flex flex-col sm:justify-center items-center px-4 pt-10 pb-10 sm:pt-0 overflow-hidden">
    {{-- Same hero photo as welcome.blade.php (rows of grey filing cabinet drawers, Maksym
    Kaharlytskyi), with a light paper-toned scrim matching the welcome hero's own treatment. --}}
    <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1569235186275-626cb53b83ce?q=80&w=2400&auto=format&fit=crop');"></div>
    <div class="absolute inset-0" style="background: radial-gradient(ellipse 70% 65% at 50% 35%, var(--paper) 0%, rgba(251,247,238,0.94) 45%, rgba(251,247,238,0.7) 72%, rgba(251,247,238,0.35) 100%);"></div>

    <div class="relative z-10 mb-2">
        {{ $logo }}
    </div>

    <div class="relative z-10 w-full sm:max-w-md mt-6 px-6 py-8 sm:px-10 bg-[var(--paper-soft)] border border-[var(--line)] shadow-sm overflow-hidden rounded-2xl">
        {{ $slot }}
    </div>
</div>
