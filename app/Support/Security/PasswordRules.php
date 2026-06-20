<?php

namespace App\Support\Security;

use Illuminate\Validation\Rules\Password;

class PasswordRules
{
    /**
     * Return the strong password rule used by all admin/student Filament forms.
     *
     * Requirements: min 10 chars, mixed case, at least one number, at least one symbol.
     * uncompromised() is intentionally omitted (requires network; breaks offline CI).
     */
    public static function strong(): Password
    {
        return Password::min(10)
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols();
    }

    /**
     * Returns rules for a "create" context — password is required and strong.
     */
    public static function requiredStrong(): array
    {
        return ['required', 'string', static::strong()];
    }

    /**
     * Returns rules for an "edit" context — password is optional but must be
     * strong if provided. An empty value leaves the existing password unchanged.
     */
    public static function optionalStrong(): array
    {
        return ['nullable', 'string', static::strong()];
    }
}
