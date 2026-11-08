<?php

namespace App\Listeners;

use App\Domain\Audit\AuditTrail;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Str;

/**
 * Failed sign-in attempts (wrong password, unknown or inactive account), for security review.
 */
class RecordFailedLogin
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function handle(Failed $event): void
    {
        $email = Str::limit((string) ($event->credentials['email'] ?? ''), 191, '');

        $this->audit->record('auth.failed', $event->user, new: ['email' => $email], description: $email, userId: $event->user?->getAuthIdentifier());
    }
}
