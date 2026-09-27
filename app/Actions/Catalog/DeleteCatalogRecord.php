<?php

namespace App\Actions\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Deletes master data only while nothing references it; otherwise it must be deactivated,
 * so products and their stock history keep a valid classification.
 */
class DeleteCatalogRecord
{
    /**
     * @param  list<string>  $relations  relations that must be empty before deletion
     *
     * @throws ValidationException
     */
    public function handle(Model $record, array $relations): void
    {
        foreach ($relations as $relation) {
            if ($record->{$relation}()->exists()) {
                throw ValidationException::withMessages([
                    'record' => "This record is used by existing {$relation} and cannot be deleted. Deactivate it instead.",
                ]);
            }
        }

        $record->delete();
    }
}
