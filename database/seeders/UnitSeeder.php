<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Baseline units of measure. Idempotent; existing units are left untouched.
 */
class UnitSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['Piece', 'pcs'], ['Box', 'box'], ['Set', 'set'], ['Pair', 'pair']] as [$name, $short]) {
            Unit::firstOrCreate(['name' => $name], ['short_name' => $short, 'is_active' => true]);
        }
    }
}
