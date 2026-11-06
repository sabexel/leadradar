<x-mail::message>
# {{ $total }} failed {{ $total === 1 ? 'job' : 'jobs' }} in the last 24 hours

<x-mail::table>
| Job | Failed |
|:----|-------:|
@foreach ($byJob as $job => $count)
| {{ $job }} | {{ $count }} |
@endforeach
</x-mail::table>

@foreach ($listed as $failed)
**{{ $failed['job'] }}** ({{ $failed['queue'] }}, {{ \Carbon\CarbonImmutable::parse($failed['failedAt'])->timezone(config('leadradar.timezone'))->format('j M H:i') }})<br>
{{ $failed['error'] }}

@endforeach
@if ($total > count($listed))
…and {{ $total - count($listed) }} more.
@endif

<x-mail::button :url="$runsUrl">
Open the Runs page
</x-mail::button>

Retry or remove them there. Failed jobs are deleted after 7 days.
</x-mail::message>
