<?php

namespace Database\Factories;

use App\Enums\PartyType;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates parties with a zero opening balance. Use SaveParty (or the HTTP endpoint) to create
 * a party with an opening balance so the ledger entry is posted.
 *
 * @extends Factory<Party>
 */
class PartyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'type' => PartyType::Supplier,
            'phone' => fake()->numerify('01#########'),
            'email' => null,
            'address' => fake()->address(),
            'opening_balance' => '0.00',
            'opening_balance_type' => null,
            'notes' => null,
            'is_active' => true,
        ];
    }

    public function supplier(): static
    {
        return $this->state(['type' => PartyType::Supplier]);
    }

    public function customer(): static
    {
        return $this->state(['type' => PartyType::Customer]);
    }

    public function both(): static
    {
        return $this->state(['type' => PartyType::Both]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
