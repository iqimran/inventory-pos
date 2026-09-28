<?php

namespace App\Actions\MobileService;

use App\Enums\ServiceJobStatus;
use App\Models\Device;
use App\Models\ServiceJob;
use App\Services\DocumentNumberGenerator;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens a repair job for a customer's device (registering the device when it is new).
 * The job starts RECEIVED; the first status log entry records who received it.
 */
class CreateServiceJob
{
    public function __construct(
        private readonly SaveDevice $saveDevice,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * @param  array{party_id: int, device_id?: ?int, device?: ?array<string, mixed>, technician_id?: ?int,
     *               complaint: string, estimated_amount?: ?string, promised_at?: ?string, notes?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data): ServiceJob
    {
        return DB::transaction(function () use ($data): ServiceJob {
            $receivedAt = now();

            if (! empty($data['device_id'])) {
                $device = Device::findOrFail($data['device_id']);

                if ($device->party_id !== $data['party_id']) {
                    throw ValidationException::withMessages(['device_id' => 'The device belongs to another customer.']);
                }
            } else {
                $device = $this->saveDevice->handle(null, ['party_id' => $data['party_id'], ...$data['device']]);
            }

            $job = ServiceJob::create([
                'job_no' => $this->numbers->next('JOB', $receivedAt),
                'party_id' => $data['party_id'],
                'device_id' => $device->id,
                'technician_id' => $data['technician_id'] ?? null,
                'complaint' => $data['complaint'],
                'estimated_amount' => Money::of($data['estimated_amount'] ?? '0'),
                'status' => ServiceJobStatus::Received,
                'received_at' => $receivedAt,
                'promised_at' => $data['promised_at'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $job->statusLogs()->create(['from_status' => null, 'to_status' => ServiceJobStatus::Received, 'created_by' => Auth::id()]);

            return $job;
        }, 3);
    }
}
