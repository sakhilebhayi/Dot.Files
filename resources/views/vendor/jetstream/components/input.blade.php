@props(['disabled' => false])

<input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'form-input rounded-md shadow-sm border-[var(--line)] text-[var(--ink)] placeholder:text-[var(--ink-faint)] focus:border-[var(--gold)] focus:ring-[var(--gold)]']) !!}>
