<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * The address is an identity, not a preference (EA-0): verified addresses are what outside shares are granted to.
     * While InfoDot owns it the address cannot change here; in break-glass local mode a change makes it unverified, so
     * the new address never inherits what the old one was trusted with.
     *
     * @param  User  $user
     * @param  array<string, mixed>  $input
     */
    public function update($user, array $input): void
    {
        $current = mb_strtolower(trim((string) $user->email));
        $requested = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $changing = $requested !== $current;

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail) use ($user, $changing, $requested): void {
                    if (! $changing) {
                        return;
                    }

                    if ($user->emailManagedByInfoDot()) {
                        $fail(__('Your email address is managed by InfoDot. Change it there.'));

                        return;
                    }

                    $taken = User::query()
                        ->whereRaw('lower(email) = ?', [$requested])
                        ->whereKeyNot($user->getKey())
                        ->exists();

                    if ($taken) {
                        $fail(__('The email has already been taken.'));
                    }
                },
            ],
            'photo' => ['nullable', 'image', 'max:1024'],
        ])->validateWithBag('updateProfileInformation');

        if (isset($input['photo'])) {
            $user->updateProfilePhoto($input['photo']);
        }

        $attributes = ['name' => $input['name']];

        if ($changing) {
            $attributes['email'] = $requested;
            $attributes['email_verified_at'] = null;
        }

        $user->forceFill($attributes)->save();
    }
}
