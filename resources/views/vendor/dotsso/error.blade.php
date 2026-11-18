{{-- R-54: every sign-in failure ends here, in words, with one next step and no automatic redirect. --}}
<x-sso.card>
    <div role="alert">
        <h1>{{ $headline }}</h1>
        <p class="sso-lede">{{ $body }}</p>
    </div>
    <div class="sso-actions">
        @if ($retry)<a class="sso-btn sso-btn--primary" href="{{ $retryUrl }}">Start again</a>@endif
        <a class="sso-btn" href="{{ $homeUrl }}">Back to Dot.Files</a>
    </div>
    <p class="sso-ref">Reference: {{ $code }}</p>
</x-sso.card>
