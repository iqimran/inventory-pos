<?php

namespace App\Domain\Sales;

use App\Enums\PartyType;
use App\Models\Party;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Customer lookup shared by POS and (later) the mobile service module.
 * Customers are parties of type CUSTOMER or BOTH.
 */
class CustomerDirectory
{
    /**
     * @return list<string>
     */
    public static function customerTypes(): array
    {
        return [PartyType::Customer->value, PartyType::Both->value];
    }

    /**
     * @return Builder<Party>
     */
    public function customers(): Builder
    {
        return Party::query()->whereIn('type', self::customerTypes());
    }

    /**
     * Active customers matching a name or phone; exact phone matches first.
     *
     * @return Collection<int, Party>
     */
    public function search(string $term, int $limit = 10): Collection
    {
        $term = trim($term);
        $digits = preg_replace('/\D+/', '', $term);

        return $this->customers()
            ->where('is_active', true)
            ->when($term !== '', function (Builder $query) use ($term, $digits) {
                $contains = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

                $query->where(function (Builder $match) use ($contains, $digits) {
                    $match->whereRaw("name LIKE ? ESCAPE '!'", [$contains]);

                    if (strlen($digits) >= 3) {
                        $match->orWhere('phone', 'like', "%{$digits}%");
                    }
                });

                if (strlen($digits) >= 3) {
                    $query->orderByRaw('CASE WHEN phone = ? THEN 0 ELSE 1 END', [$digits]);
                }
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'phone', 'type', 'balance']);
    }
}
