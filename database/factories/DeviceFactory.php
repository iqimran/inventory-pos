<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'party_id' => Party::factory()->customer(),
            'brand' => fake()->randomElement(['Samsung', 'Xiaomi', 'Apple', 'Realme', 'Oppo']),
            'model' => strtoupper(fake()->bothify('?-##')),
            'imei1' => null,
            'imei2' => null,
            'serial_no' => strtoupper(fake()->unique()->bothify('SN########')),
            'color' => fake()->safeColorName(),
            'notes' => null,
        ];
    }
}
