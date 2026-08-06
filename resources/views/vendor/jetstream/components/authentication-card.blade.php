<div class="min-h-screen flex flex-col sm:justify-center items-center px-4 pt-10 pb-10 sm:pt-0">
    <div class="mb-2">
        {{ $logo }}
    </div>

    <div class="w-full sm:max-w-md mt-6 px-6 py-8 sm:px-10 bg-[var(--paper-soft)] border border-[var(--line)] shadow-sm overflow-hidden rounded-2xl">
        {{ $slot }}
    </div>
</div>
