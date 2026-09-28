<?php

namespace App\Http\Resources;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Device
 */
class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'party_id' => $this->party_id,
            'brand' => $this->brand,
            'model' => $this->model,
            'name' => $this->displayName(),
            'imei1' => $this->imei1,
            'imei2' => $this->imei2,
            'serial_no' => $this->serial_no,
            'color' => $this->color,
            'notes' => $this->notes,
            'party' => $this->whenLoaded('party', fn () => ['id' => $this->party->id, 'name' => $this->party->name, 'phone' => $this->party->phone]),
            'service_jobs_count' => $this->whenCounted('serviceJobs'),
        ];
    }
}
