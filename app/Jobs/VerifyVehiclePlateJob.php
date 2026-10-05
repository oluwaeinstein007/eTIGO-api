<?php

namespace App\Jobs;

use App\Models\Driver;
use App\Services\KycVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class VerifyVehiclePlateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        private Driver $driver,
        private string $plateNumber,
    ) {}

    public function handle(KycVerificationService $kycService): void
    {
        try {
            $kycService->verifyVehiclePlate($this->driver, $this->plateNumber);
        } catch (\Throwable $e) {
            Log::error('VerifyVehiclePlateJob failed', [
                'driver_id' => $this->driver->id,
                'plate' => $this->plateNumber,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
