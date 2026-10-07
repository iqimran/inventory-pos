<?php

namespace Tests\Feature\Foundation;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * The scheduler container runs nightly, report-only integrity checks.
 */
class SchedulerTest extends TestCase
{
    public function test_integrity_checks_are_scheduled_nightly_without_fix()
    {
        $events = collect(app(Schedule::class)->events())->mapWithKeys(fn ($event) => [$event->command => $event->expression]);

        foreach (['inventory:reconcile', 'ledger:reconcile'] as $command) {
            $entry = $events->first(fn ($expression, $line) => str_contains($line, $command));
            $this->assertSame('0 2 * * *', $entry, "{$command} must run nightly");
            $this->assertFalse($events->keys()->contains(fn ($line) => str_contains($line, $command) && str_contains($line, '--fix')), "{$command} must never auto-fix");
        }
    }
}
