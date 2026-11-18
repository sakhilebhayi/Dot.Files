{{-- R-54: the claim page, in Dot.Files' Achromatic Ledger. Shown when an account here already uses the email address of the
     InfoDot sign-in. Nothing on the account is replaced; the code proves the address. Variables come from the shared package. --}}
<x-sso.card>
    <h1>Confirm it is you</h1>
    <p class="sso-lede">An account here already uses <strong>{{ $maskedEmail }}</strong>. We sent a {{ $codeLength }}-digit code to that address. Enter it to connect the two. Your folders, files and teams stay as they are.</p>

    @if (session('dotsso_status'))
        <p class="sso-note" role="status">{{ session('dotsso_status') }}</p>
    @endif

    <form method="POST" action="{{ $verifyUrl }}" novalidate>
        @csrf
        <label class="sso-label" for="code">Code from the email</label>
        <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code"
               maxlength="{{ $codeLength }}" required autofocus class="sso-input"
               aria-describedby="code-help code-error" aria-invalid="{{ $errors->has('code') ? 'true' : 'false' }}">
        <p id="code-help" class="sso-help">It works for {{ $minutes }} minutes. You have {{ $maxAttempts }} tries.</p>
        <p id="code-error" class="sso-error" role="alert">{{ $errors->first('code') }}</p>
        <button type="submit" class="sso-btn sso-btn--primary sso-btn--block">Confirm and continue</button>
    </form>

    <div class="sso-aside">
        <form method="POST" action="{{ $resendUrl }}">@csrf<button type="submit" class="sso-btn sso-btn--quiet">Send a new code</button></form>
        <form method="POST" action="{{ $cancelUrl }}">@csrf<button type="submit" class="sso-btn sso-btn--quiet">Cancel</button></form>
    </div>
</x-sso.card>
