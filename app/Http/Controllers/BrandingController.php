<?php

namespace App\Http\Controllers;

use App\Support\OrganizationProfile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the organization logo. Public (the login page and browser tab icon need it before sign-in).
 */
class BrandingController extends Controller
{
    public function logo(OrganizationProfile $organization): StreamedResponse
    {
        $path = $organization->logoPath();

        abort_unless($path, 404);

        // URLs carry a per-upload version (?v=...), so the file can be cached for a long time.
        return Storage::disk($organization->disk())->response($path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
