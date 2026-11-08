<?php

namespace App\Listeners;

use App\Domain\Audit\AuditTrail;
use App\Models\User;
use Illuminate\Auth\Events\Logout;

class RecordLogout
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function handle(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record('auth.logout', $event->user, description: $event->user->email, userId: $event->user->getKey());
        }
    }
}
