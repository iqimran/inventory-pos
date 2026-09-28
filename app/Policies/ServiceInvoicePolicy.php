<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ServiceInvoice;
use App\Models\User;

class ServiceInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ServiceView->value);
    }

    public function view(User $user, ServiceInvoice $invoice): bool
    {
        return $user->can(Permission::ServiceView->value);
    }
}
