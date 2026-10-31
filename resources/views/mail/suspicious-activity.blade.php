<x-mail::message>
# {{ __('Unusual activity to review') }}

{{ __('Dot.Files noticed a pattern on one of :team\'s records that is worth a look. This is not an accusation — it is the evidence, for you to judge.', ['team' => $team->name]) }}

**{{ $ruleLabel }}** — {{ $file->name }}

@if ($flag->rule === 'version-churn')
{{ __(':actor saved new content for this record :count times within :minutes minutes.', ['actor' => $actorName, 'count' => $flag->evidence['count'] ?? '?', 'minutes' => $flag->evidence['window_minutes'] ?? '?']) }}
@elseif ($flag->rule === 'replace-then-trash')
{{ __('This record\'s content was replaced (version :version:by), and :actor moved it to the trash within :minutes minutes. The earlier content is still in its version history, and the record sits in the trash — nothing is lost.', ['version' => $flag->evidence['replaced_version'] ?? '?', 'by' => isset($flag->evidence['replaced_by']) ? ' '.__('by').' '.$flag->evidence['replaced_by'] : '', 'actor' => $actorName, 'minutes' => $flag->evidence['window_minutes'] ?? '?']) }}
@endif

<x-mail::button :url="$url">
{{ __('Review it on the approvals desk') }}
</x-mail::button>

{{ __('Every version of the record is preserved, so whatever happened can be undone from the history.') }}

{{ config('app.name') }}
</x-mail::message>
