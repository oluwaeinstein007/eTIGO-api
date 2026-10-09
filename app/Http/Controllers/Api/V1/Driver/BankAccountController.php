<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Contracts\FlutterwaveWalletGateway;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\StoreBankAccountRequest;
use App\Http\Resources\BankAccountResource;
use App\Models\AuditLog;
use App\Models\BankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BankAccountController extends Controller
{
    public function __construct(private FlutterwaveWalletGateway $flutterwaveGateway) {}

    public function store(StoreBankAccountRequest $request): JsonResponse
    {
        $driver = $request->user()->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        try {
            $resolved = $this->flutterwaveGateway->resolveAccountNumber(
                $request->validated('account_number'),
                $request->validated('bank_code'),
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Unable to verify bank account. Please check the details and try again.'], 422);
        }

        $bankAccount = DB::transaction(function () use ($driver, $request, $resolved) {
            // Set existing accounts as non-primary
            BankAccount::where('driver_id', $driver->id)->update(['is_primary' => false]);

            $bankAccount = BankAccount::create([
                'driver_id' => $driver->id,
                'bank_code' => $request->validated('bank_code'),
                'account_number' => $request->validated('account_number'),
                'account_name' => $resolved['account_name'],
                'is_verified' => true,
                'is_primary' => true,
            ]);

            AuditLog::record($bankAccount, 'bank_account.created', $request->user());

            return $bankAccount;
        });

        return response()->json([
            'message' => 'Bank account added and verified.',
            'bank_account' => new BankAccountResource($bankAccount),
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $driver = $request->user()->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        $bankAccount = $driver->primaryBankAccount;

        if (! $bankAccount) {
            return response()->json(['message' => 'No bank account on file.'], 404);
        }

        return response()->json([
            'bank_account' => new BankAccountResource($bankAccount),
        ]);
    }
}
