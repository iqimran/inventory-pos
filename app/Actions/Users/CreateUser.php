<?php

namespace App\Actions\Users;

use App\Domain\Audit\AuditTrail;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateUser
{
    /**
     * @param  array{name: string, email: string, password: string, role: string, permissions?: list<string>, is_active?: bool}  $data
     */
    public function handle(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            $user->syncRoles([$data['role']]);
            $user->syncPermissions($user->isAdmin() ? [] : ($data['permissions'] ?? []));

            app(AuditTrail::class)->record('user.created', $user, new: [
                'name' => $user->name, 'email' => $user->email, 'is_active' => (bool) $user->is_active,
                'role' => $data['role'], 'permissions' => $user->getDirectPermissions()->pluck('name')->sort()->values()->all(),
            ], description: $user->email);

            return $user;
        });
    }
}
