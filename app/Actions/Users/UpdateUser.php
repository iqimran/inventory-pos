<?php

namespace App\Actions\Users;

use App\Domain\Audit\AuditTrail;
use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateUser
{
    public function __construct(private readonly EnsureAdminRemains $ensureAdminRemains) {}

    /**
     * @param  array{name: string, email: string, password?: ?string, role: string, permissions?: list<string>}  $data
     *
     * @throws ValidationException
     */
    public function handle(User $actor, User $user, array $data): User
    {
        return DB::transaction(function () use ($actor, $user, $data): User {
            $roleChanged = ! $user->hasRole($data['role']);

            if ($roleChanged && $actor->is($user)) {
                throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
            }

            if ($roleChanged && $data['role'] !== SystemRole::Admin->value) {
                $this->ensureAdminRemains->handle($user, 'role');
            }

            $before = $this->accessSnapshot($user);

            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
            ]);

            $passwordChanged = filled($data['password'] ?? null);

            if ($passwordChanged) {
                $user->password = $data['password'];
            }

            $user->save();

            $user->syncRoles([$data['role']]);
            $user->syncPermissions($user->isAdmin() ? [] : ($data['permissions'] ?? []));

            // The password itself is never stored; only the fact that it changed.
            $after = $this->accessSnapshot($user->fresh());
            $before['password_changed'] = false;
            $after['password_changed'] = $passwordChanged;
            app(AuditTrail::class)->recordChanges('user.updated', $user, $before, $after, $user->email);

            return $user;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function accessSnapshot(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getRoleNames()->first(),
            'permissions' => $user->getDirectPermissions()->pluck('name')->sort()->values()->all(),
        ];
    }
}
