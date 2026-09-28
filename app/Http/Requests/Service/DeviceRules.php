<?php

namespace App\Http\Requests\Service;

use App\Domain\MobileService\Imei;
use App\Models\Device;
use Closure;

/**
 * Device field normalisation and validation shared by the device form and job intake.
 */
final class DeviceRules
{
    /**
     * @param  array<string, mixed>  $device
     * @return array<string, mixed>
     */
    public static function normalize(array $device): array
    {
        foreach (['imei1', 'imei2'] as $field) {
            $device[$field] = Imei::normalize(isset($device[$field]) ? (string) $device[$field] : null);
        }

        foreach (['brand', 'model', 'serial_no', 'color', 'notes'] as $field) {
            $value = isset($device[$field]) ? trim((string) $device[$field]) : '';
            $device[$field] = $value === '' ? null : $value;
        }

        if ($device['serial_no'] !== null) {
            $device['serial_no'] = strtoupper($device['serial_no']);
        }

        return $device;
    }

    /**
     * @param  string  $prefix  e.g. '' or 'device.'
     * @param  Closure(): ?int  $partyId  owner, to reject an IMEI already registered to another of their devices
     * @return array<string, mixed>
     */
    public static function rules(string $prefix, Closure $partyId, ?Device $ignore = null, bool $required = true): array
    {
        $presence = $required ? 'required' : 'nullable';

        return [
            "{$prefix}brand" => [$presence, 'string', 'max:100'],
            "{$prefix}model" => [$presence, 'string', 'max:100'],
            "{$prefix}imei1" => ['nullable', 'string', self::imeiRule($partyId, $ignore)],
            "{$prefix}imei2" => ['nullable', 'string', "different:{$prefix}imei1", self::imeiRule($partyId, $ignore)],
            "{$prefix}serial_no" => ['nullable', 'string', 'max:64'],
            "{$prefix}color" => ['nullable', 'string', 'max:50'],
            "{$prefix}notes" => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $prefix): array
    {
        return [
            "{$prefix}imei2.different" => 'IMEI 2 must differ from IMEI 1.',
        ];
    }

    private static function imeiRule(Closure $partyId, ?Device $ignore): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($partyId, $ignore): void {
            if (! Imei::isValid((string) $value)) {
                $fail('The IMEI must be 15 digits with a valid check digit.');

                return;
            }

            $owner = $partyId();

            if ($owner && Device::query()
                ->where('party_id', $owner)
                ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
                ->where(fn ($q) => $q->where('imei1', $value)->orWhere('imei2', $value))
                ->exists()) {
                $fail('This customer already has a device with this IMEI.');
            }
        };
    }
}
