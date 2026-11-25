{{--
    Shown with a 503 when InfoDot has switched Dot.Files off (maintenance or unavailable). It says so plainly and reads nothing of the
    company's files: everything stored here is kept exactly as it is. It is not a purchase or permission problem.
--}}
@extends('errors._ecosystem-layout', ['code' => 503, 'plainHeader' => true])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#a97710" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7.5A1.5 1.5 0 015.5 6h4l2 2h7A1.5 1.5 0 0120 9.5v8a1.5 1.5 0 01-1.5 1.5h-13A1.5 1.5 0 014 17.5v-10z"/><path d="M9.5 13.5h5"/></svg>
@endsection

@section('title', 'Dot.Files is not available right now')
@section('message', ($message ?? 'Dot.Files is temporarily unavailable.').' The files and folders already here are kept as they are.')

@section('actions')
    @if ($signedIn ?? false)
        <form method="POST" action="{{ route('logout') }}" style="margin: 0;">
            @csrf
            <button type="submit" class="press" style="padding: 12px 24px; min-height: 44px; border: 0; background: var(--accent); color: #2b2a25; font-family: var(--font-display); font-weight: 600; font-size: 15px; border-radius: 999px; cursor: pointer;">Sign out</button>
        </form>
    @endif
@endsection
