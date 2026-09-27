<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class TransactionDate
{
    /**
     * Moment a dated business transaction occurred: the document's date at the current time of
     * day, so same-day entries keep their recording order on statements.
     */
    public static function at(DateTimeInterface|string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date)->setTimeFrom(now());
    }
}
