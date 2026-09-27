<?php

namespace App\Http\Requests\Catalog;

use App\Models\Unit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UnitRequest extends CatalogRequest
{
    protected function modelClass(): string
    {
        return Unit::class;
    }

    protected function routeParameter(): string
    {
        return 'unit';
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50', Rule::unique('units')->ignore($this->record())],
            'short_name' => ['required', 'string', 'max:20', Rule::unique('units')->ignore($this->record())],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
