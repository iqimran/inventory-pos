<?php

namespace App\Actions\Catalog;

use App\Domain\Audit\AuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

        DB::transaction(function () use ($record): void {
            app(AuditTrail::class)->record(
                Str::snake(class_basename($record)).'.deleted',
                $record,
                old: array_diff_key($record->attributesToArray(), array_flip(['created_at', 'updated_at'])),
                description: (string) ($record->getAttribute('name') ?? $record->getKey()),
            );
            $record->delete();
        });
    }
}
