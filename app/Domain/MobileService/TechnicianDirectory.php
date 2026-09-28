<?php

namespace App\Domain\MobileService;

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Technicians are active users who may manage service jobs (directly, through a role, or as Admin).
 */
class TechnicianDirectory
{
    /**
     * @return Builder<User>
     */
    public function query(): Builder
    {
        $permission = Permission::ServiceManage->value;

        return User::query()
            ->active()
            ->where(fn (Builder $query) => $query
                ->whereHas('permissions', fn (Builder $q) => $q->where('name', $permission))
                ->orWhereHas('roles', fn (Builder $q) => $q
                    ->where('name', SystemRole::Admin->value)
                    ->orWhereHas('permissions', fn (Builder $p) => $p->where('name', $permission))));
    }

    public function isTechnician(int $userId): bool
    {
        return $this->query()->whereKey($userId)->exists();
    }

    /**
     * @return Collection<int, array{id: int, name: string}>
     */
    public function options(): Collection
    {
        return $this->query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name]);
    }
}
