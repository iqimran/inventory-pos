<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\OrganizationSettingsRequest;
use App\Support\OrganizationProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organization details printed at the top of sale and service invoices, and the logo used across the app.
 */
class OrganizationController extends Controller
{
    public function edit(OrganizationProfile $organization): Response
    {
        Gate::authorize(Permission::SettingsManage->value);

        return Inertia::render('settings/organization', ['organization' => $organization->details()]);
    }

    public function update(OrganizationSettingsRequest $request, OrganizationProfile $organization): RedirectResponse
    {
        $organization->update($request->details(), $request->file('logo'), $request->boolean('remove_logo'));

        return to_route('organization.edit')->with('success', 'Organization details saved.');
    }
}
