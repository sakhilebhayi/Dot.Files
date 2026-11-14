@extends('errors._ecosystem-layout', ['code' => 403, 'plainHeader' => true])

{{-- R-50: the company does not hold Dot.Files. No file, folder or count is ever passed to this view. --}}

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#a97710" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7.5A1.5 1.5 0 015.5 6h4l2 2h7A1.5 1.5 0 0120 9.5v8a1.5 1.5 0 01-1.5 1.5h-13A1.5 1.5 0 014 17.5v-10z"/><path d="M9.5 13.5h5"/></svg>
@endsection

@section('title', $title)
@section('message', $message)

@section('actions')
    <a href="{{ $platformsUrl }}" class="press" style="padding: 12px 24px; background: var(--accent); color: #2b2a25; font-family: var(--font-display); font-weight: 600; font-size: 15px; border-radius: 999px; text-decoration: none;">Open Platforms in InfoDot</a>

    @if ($otherTeams->isNotEmpty())
        <div style="flex: 0 0 100%; margin-top: 20px;">
            <p style="font-size: 14px; line-height: 1.5; color: var(--ink-soft); margin: 0 0 8px;">Or carry on in another workspace:</p>
            <div style="display: flex; flex-wrap: wrap; justify-content: center; gap: 4px 20px;">
                @foreach ($otherTeams as $team)
                    <form method="POST" action="{{ route('current-team.update') }}" style="margin: 0;">
                        @method('PUT')
                        @csrf
                        <input type="hidden" name="team_id" value="{{ $team->id }}">
                        <button type="submit" class="press link-underline" style="padding: 10px 0; min-height: 44px; border: 0; background-color: transparent; color: var(--ink); font-family: var(--font-body); font-size: 15px; font-weight: 500; cursor: pointer;">{{ $team->name }}</button>
                    </form>
                @endforeach
            </div>
        </div>
    @endif
@endsection
