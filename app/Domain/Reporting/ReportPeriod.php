<?php

namespace App\Domain\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * A reporting date range in the shop's local time zone, and the SQL to bucket rows into its
 * days or months.
 *
 * DATETIME columns are stored in the application time zone, so they are filtered with UTC
 * bounds (index-friendly) and shifted to local time only for grouping. DATE columns
 * (purchase_date, expense_date…) are already local business dates: filter them with
 * whereDate() on dateBounds() (SQLite stores DATE values with a time part).
 */
final class ReportPeriod
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $groupBy = 'day',
    ) {}

    public static function make(string $from, string $to, string $groupBy = 'day'): self
    {
        $zone = self::timezone();

        return new self(
            CarbonImmutable::parse($from, $zone)->startOfDay(),
            CarbonImmutable::parse($to, $zone)->endOfDay(),
            $groupBy === 'month' ? 'month' : 'day',
        );
    }

    public static function timezone(): string
    {
        return (string) (config('reports.timezone') ?: config('app.timezone'));
    }

    /**
     * Inclusive bounds for DATETIME columns, in the storage (application) time zone.
     *
     * @return array{0: string, 1: string}
     */
    public function datetimeBounds(): array
    {
        $storage = config('app.timezone');

        return [
            $this->from->setTimezone($storage)->format('Y-m-d H:i:s'),
            $this->to->setTimezone($storage)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Inclusive bounds for DATE columns.
     *
     * @return array{0: string, 1: string}
     */
    public function dateBounds(): array
    {
        return [$this->from->toDateString(), $this->to->toDateString()];
    }

    /**
     * Local-time bucket (Y-m-d or Y-m) for a DATETIME column.
     */
    public function bucket(string $column): Expression
    {
        return $this->bucketExpression($column, $this->offsetSeconds());
    }

    public function label(): string
    {
        return $this->from->toDateString().' – '.$this->to->toDateString();
    }

    /**
     * Local offset from the storage time zone, in seconds (fixed for the period; zones with daylight
     * saving can be off by an hour for rows near a transition).
     */
    private function offsetSeconds(): int
    {
        $local = $this->to->utcOffset();
        $storage = $this->to->setTimezone(config('app.timezone'))->utcOffset();

        return ($local - $storage) * 60;
    }

    private function bucketExpression(string $column, int $offset): Expression
    {
        // $column comes from code, never from input; $offset is an int.
        $mysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

        if ($mysql) {
            $shifted = $offset !== 0 ? "DATE_ADD({$column}, INTERVAL {$offset} SECOND)" : $column;

            return DB::raw($this->groupBy === 'month' ? "DATE_FORMAT({$shifted}, '%Y-%m')" : "DATE({$shifted})");
        }

        $modifier = $offset !== 0 ? ", '".sprintf('%+d', $offset)." seconds'" : '';

        return DB::raw($this->groupBy === 'month' ? "strftime('%Y-%m', {$column}{$modifier})" : "date({$column}{$modifier})");
    }
}
