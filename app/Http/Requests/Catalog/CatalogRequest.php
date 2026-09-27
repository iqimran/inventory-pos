<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Base request for catalogue records: authorizes create/update through the model policy
 * based on whether the route carries an existing record.
 */
abstract class CatalogRequest extends FormRequest
{
    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    abstract protected function routeParameter(): string;

    public function authorize(): bool
    {
        $record = $this->record();

        return $record ? $this->user()->can('update', $record) : $this->user()->can('create', $this->modelClass());
    }

    protected function record(): ?Model
    {
        $record = $this->route($this->routeParameter());

        return $record instanceof Model ? $record : null;
    }

    /**
     * Reference must exist and be active — unless it is the value already stored,
     * so records assigned to a since-deactivated parent can still be edited.
     */
    protected function activeOrCurrent(string $table, ?int $currentId): Exists
    {
        return Rule::exists($table, 'id')->where(function ($query) use ($currentId) {
            $query->where(function ($q) use ($currentId) {
                $q->where('is_active', true);

                if ($currentId) {
                    $q->orWhere('id', $currentId);
                }
            });
        });
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach (['name', 'short_name', 'description'] as $field) {
            if (is_string($this->input($field))) {
                $trimmed[$field] = trim($this->input($field)) === '' && $field === 'description' ? null : trim($this->input($field));
            }
        }

        $this->merge($trimmed);
    }
}
