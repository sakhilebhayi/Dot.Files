<x-jet-action-section>
    <x-slot name="title">
        {{ __('Delete Team') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Permanently delete this team.') }}
    </x-slot>

    <x-slot name="content">
        @if ($requestedDeletion)
            <div class="max-w-xl text-sm text-amber-600 font-bold">
                {{ __('Deletion requested. A platform operator must approve it before this team and its data are removed.') }}
            </div>
        @else
            <div class="max-w-xl text-sm text-gray-600">
                {{ __('Once a team is deleted, all of its resources and data will be permanently deleted. Before deleting this team, please download any data or information regarding this team that you wish to retain.') }}
            </div>

            <div class="mt-5">
                <x-jet-danger-button wire:click="$toggle('confirmingTeamDeletion')" wire:loading.attr="disabled">
                    {{ __('Delete Team') }}
                </x-jet-danger-button>
            </div>

            <!-- Delete Team Confirmation Modal -->
            <x-jet-confirmation-modal wire:model="confirmingTeamDeletion">
                <x-slot name="title">
                    {{ __('Delete Team') }}
                </x-slot>

                <x-slot name="content">
                    {{ __('Are you sure you want to request deletion of this team? A platform operator will need to approve the request before anything is actually deleted.') }}
                </x-slot>

                <x-slot name="footer">
                    <x-jet-secondary-button wire:click="$toggle('confirmingTeamDeletion')" wire:loading.attr="disabled">
                        {{ __('Nevermind') }}
                    </x-jet-secondary-button>

                    <x-jet-danger-button class="ml-2" wire:click="deleteTeam" wire:loading.attr="disabled">
                        {{ __('Request Deletion') }}
                    </x-jet-danger-button>
                </x-slot>
            </x-jet-confirmation-modal>
        @endif
    </x-slot>
</x-jet-action-section>
