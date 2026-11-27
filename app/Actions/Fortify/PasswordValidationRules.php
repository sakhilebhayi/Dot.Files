<?php

namespace App\Actions\Fortify;

use Laravel\Fortify\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array
     */
    protected function passwordRules()
    {
        // EA-1: break-glass local accounts only (InfoDot owns sign-in); 12 characters minimum.
        return ['required', 'string', (new Password)->length(12), 'confirmed'];
    }
}
