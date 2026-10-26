<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Deletion Requests
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-4 bg-green-100 text-green-800 rounded-lg">
                    {{ session('status') }}
                </div>
            @endif

            <div class="bg-white p-6 rounded-lg shadow">
                <h3 class="font-bold text-lg mb-4">Pending Team Deletions ({{ $pendingTeamRequests->count() }})</h3>
                @forelse ($pendingTeamRequests as $request)
                    <div class="border-b py-3">
                        <p class="font-bold">{{ $request->team->name }}</p>
                        <p class="text-sm text-gray-500 mb-2">Requested by {{ $request->requester->name }}</p>
                        <div class="flex items-center gap-2">
                            <form method="POST" action="{{ route('operator.deletion-requests.team.approve', $request) }}">
                                @csrf
                                <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-bold">Approve</button>
                            </form>
                            <form method="POST" action="{{ route('operator.deletion-requests.team.reject', $request) }}" class="flex items-center gap-2">
                                @csrf
                                <input aria-label="Reason for rejecting" type="text" name="reason" placeholder="Reason for rejecting" required class="border rounded-lg px-2 py-1 text-sm">
                                <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-bold">Reject</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="text-gray-500 text-sm">No pending team deletions.</p>
                @endforelse
            </div>

            <div class="bg-white p-6 rounded-lg shadow">
                <h3 class="font-bold text-lg mb-4">Pending Bulk File Deletions ({{ $pendingBulkRequests->count() }})</h3>
                @forelse ($pendingBulkRequests as $request)
                    <div class="border-b py-3">
                        <p class="font-bold">{{ $request->team->name }} &mdash; {{ count($request->object_ids) }} item(s)</p>
                        <p class="text-sm text-gray-500 mb-2">Requested by {{ $request->requester->name }}</p>
                        <div class="flex items-center gap-2">
                            <form method="POST" action="{{ route('operator.deletion-requests.bulk.approve', $request) }}">
                                @csrf
                                <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-bold">Approve</button>
                            </form>
                            <form method="POST" action="{{ route('operator.deletion-requests.bulk.reject', $request) }}" class="flex items-center gap-2">
                                @csrf
                                <input aria-label="Reason for rejecting" type="text" name="reason" placeholder="Reason for rejecting" required class="border rounded-lg px-2 py-1 text-sm">
                                <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-bold">Reject</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="text-gray-500 text-sm">No pending bulk deletions.</p>
                @endforelse
            </div>

        </div>
    </div>
</x-app-layout>
