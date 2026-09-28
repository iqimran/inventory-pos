<?php

namespace App\Actions\MobileService;

use App\Models\Device;

/**
 * Creates or updates a customer's device. The owner is fixed once the device exists,
 * so job history always stays with the customer who brought it in.
 */
class SaveDevice
{
    /**
     * @param  array{party_id?: int, brand: string, model: string, imei1?: ?string, imei2?: ?string,
     *               serial_no?: ?string, color?: ?string, notes?: ?string}  $data
     */
    public function handle(?Device $device, array $data): Device
    {
        $attributes = [
            'brand' => $data['brand'],
            'model' => $data['model'],
            'imei1' => $data['imei1'] ?? null,
            'imei2' => $data['imei2'] ?? null,
            'serial_no' => $data['serial_no'] ?? null,
            'color' => $data['color'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        if ($device) {
            $device->update($attributes);

            return $device;
        }

        return Device::create(['party_id' => $data['party_id'], ...$attributes]);
    }
}
