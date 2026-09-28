<?php

namespace App\Support;

use App\Domain\Audit\AuditTrail;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The organization's branding: name, address, contact number and invoice footer printed at the
 * top of sale and service invoices, plus one logo used for the sidebar, login page and browser tab
 * icon (not on printed documents).
 *
 * Values saved from Settings → Organization win; until a value is saved, the SHOP_* environment
 * values (config/shop.php) are used, so existing installs keep printing what they printed before.
 */
class OrganizationProfile
{
    /** Setting key => config/shop.php key. */
    private const FIELDS = [
        'organization.name' => 'name',
        'organization.address' => 'address',
        'organization.phone' => 'phone',
        'organization.receipt_footer' => 'receipt_footer',
    ];

    private const LOGO_KEY = 'organization.logo';

    /** Private disk: the logo is served by BrandingController, so no storage symlink is needed. */
    private const DISK = 'local';

    /** @var array<string, ?string>|null per-request memo (shared props, blade and pages all read it) */
    private ?array $saved = null;

    /**
     * @return array{name: string, address: ?string, phone: ?string, receipt_footer: ?string, logo_url: ?string}
     */
    public function details(): array
    {
        $saved = $this->saved();
        $details = [];

        foreach (self::FIELDS as $key => $field) {
            $details[$field] = array_key_exists($key, $saved) ? $saved[$key] : config("shop.{$field}");
        }

        $details['name'] = (string) ($details['name'] ?: config('app.name'));
        $details['logo_url'] = $this->logoUrl();

        return $details;
    }

    /**
     * Header for printed documents (receipts, slips, invoices, vouchers): name, address, contact
     * number and footer. The logo is deliberately left off printed documents.
     *
     * @return array{name: string, address: ?string, phone: ?string, receipt_footer: ?string}
     */
    public function documentHeader(): array
    {
        return array_diff_key($this->details(), ['logo_url' => true]);
    }

    /**
     * Name and logo for the application chrome (sidebar, login page, browser tab).
     *
     * @return array{name: string, logo_url: ?string}
     */
    public function branding(): array
    {
        $details = $this->details();

        return ['name' => $details['name'], 'logo_url' => $details['logo_url']];
    }

    public function logoPath(): ?string
    {
        $path = $this->saved()[self::LOGO_KEY] ?? null;

        return $path && Storage::disk(self::DISK)->exists($path) ? $path : null;
    }

    public function logoUrl(): ?string
    {
        $path = $this->logoPath();

        // The stored file name is unique per upload, so it doubles as a cache-busting version.
        return $path ? route('branding.logo', ['v' => pathinfo($path, PATHINFO_FILENAME)]) : null;
    }

    public function disk(): string
    {
        return self::DISK;
    }

    /**
     * @param  array{name: string, address?: ?string, phone?: ?string, receipt_footer?: ?string}  $details
     * @param  UploadedFile|null  $logo  a new logo to store
     * @param  bool  $removeLogo  drop the current logo (ignored when a new one is uploaded)
     */
    public function update(array $details, ?UploadedFile $logo = null, bool $removeLogo = false): void
    {
        $previousLogo = $this->saved()[self::LOGO_KEY] ?? null;
        $before = array_intersect_key($this->details(), array_flip(self::FIELDS));
        $newLogo = $logo?->storeAs('branding', 'logo-'.Str::random(16).'.'.$logo->extension(), self::DISK);

        DB::transaction(function () use ($details, $newLogo, $removeLogo): void {
            foreach (self::FIELDS as $key => $field) {
                Setting::updateOrCreate(['key' => $key], ['value' => $details[$field] ?? null, 'updated_by' => Auth::id()]);
            }

            if ($newLogo || $removeLogo) {
                Setting::updateOrCreate(['key' => self::LOGO_KEY], ['value' => $newLogo ?: null, 'updated_by' => Auth::id()]);
            }
        });

        $this->saved = null;
        $after = array_intersect_key($this->details(), array_flip(self::FIELDS));

        if ($newLogo || ($removeLogo && $previousLogo)) {
            $before['logo'] = $previousLogo ? 'set' : null;
            $after['logo'] = $newLogo ? 'replaced' : null;
        }

        app(AuditTrail::class)->recordChanges('settings.organization_updated', null, $before, $after);

        // Replaced or removed files are deleted only after the new settings are committed.
        if ($previousLogo && ($newLogo || $removeLogo)) {
            Storage::disk(self::DISK)->delete($previousLogo);
        }

        $this->saved = null;
    }

    /**
     * @return array<string, ?string>
     */
    private function saved(): array
    {
        return $this->saved ??= Setting::whereIn('key', [...array_keys(self::FIELDS), self::LOGO_KEY])->pluck('value', 'key')->all();
    }
}
