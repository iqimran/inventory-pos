<?php

namespace Database\Seeders;

use App\Models\ExpenseType;
use Illuminate\Database\Seeder;

/**
 * Common shop expense types. Idempotent; existing types are left untouched.
 */
class ExpenseTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Rent', 'Electricity', 'Internet', 'Salary', 'Transport', 'Tools/maintenance', 'Miscellaneous'] as $name) {
            ExpenseType::firstOrCreate(['name' => $name], ['is_active' => true]);
        }
    }
}
