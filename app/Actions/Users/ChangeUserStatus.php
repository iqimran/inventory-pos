<?php

namespace App\Actions\Users;

use App\Domain\Audit\AuditTrail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeUserStatus
{
    public function __construct(private readonly EnsureAdminRemains $ensureAdminRemains) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, User $user, bool $active): User
    {
        if ($actor->is($user)) {
            throw ValidationException::withMessages(['is_active' => 'You cannot change the status of your own account.']);
        }

        return DB::transaction(function () use ($user, $active): User {
            if (! $active) {
                $this->ensureAdminRemains->handle($user, 'is_active');
                // Revoke API access immediately; web sessions are ended by EnsureUserIsActive.
                $user->tokens()->delete();
            }

            $wasActive = (bool) $user->is_active;
            $user->is_active = $active;
            $user->save();

            if ($wasActive !== $active) {
                app(AuditTrail::class)->record($active ? 'user.activated' : 'user.deactivated', $user, ['is_active' => $wasActive], ['is_active' => $active], $user->email);
            }

            return $user;
        });
    }
}
