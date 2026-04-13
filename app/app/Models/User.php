<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Phase-1 admin gate.
     *
     * The only way to become a User right now is `php artisan make:filament-user`
     * (wrapped by `make filament-user`), which is an operator-run command on the
     * host. So "authenticated" effectively means "the operator seeded this
     * account" — treating every authenticated user as an admin is safe until we
     * open up public / EVE-SSO signup.
     *
     * When UsersCharacters lands with role data on the users table (via
     * spatie/laravel-permission), swap this out for:
     *
     *     return $this->hasRole('alliance-admin');
     *
     * …and delete this docstring.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
