<?php

namespace App\Domain\Reporting;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * Paginates a GROUPED report query by running it once.
 *
 * The standard paginator re-runs the whole grouped query to count its groups, which doubles the
 * cost of heavy aggregates (measured: product revenue 301 ms count + 373 ms data for a year).
 * Use only when the number of groups is naturally bounded (e.g. one row per product), since
 * all aggregated rows are fetched; the raw rows behind them never leave the database.
 */
final class AggregatePaginator
{
    /**
     * @param  callable(object): array<string, mixed>  $map
     */
    public static function paginate(Builder $query, int $perPage, string $pageName, callable $map): LengthAwarePaginator
    {
        $page = Paginator::resolveCurrentPage($pageName);
        $rows = $query->get();

        return (new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->map($map)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName],
        ))->withQueryString();
    }
}
