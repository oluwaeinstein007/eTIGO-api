<?php

namespace App\Http\Requests\Driver;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Services\DriverDocumentComplianceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::enum(DocumentType::class)],
            'document' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            // Roadworthiness and vehicle licence are only useful with their
            // expiry date, and so is a renewed driving licence.
            'expires_at' => [
                Rule::requiredIf(fn () => (DocumentType::tryFrom((string) $this->input('type'))?->requiresExpiry() ?? false) || $this->isLicenceRenewal()),
                'nullable', 'date', 'after:today',
            ],
            // The number printed on the paper (a renewed licence may carry a new one).
            'document_number' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * A driving licence uploaded while an approved one is on file.
     */
    public function isLicenceRenewal(): bool
    {
        return $this->input('type') === DocumentType::DrivingLicence->value
            && $this->user()?->driver?->documents()
                ->where('type', DocumentType::DrivingLicence)
                ->where('status', DocumentStatus::Approved)
                ->whereNull('revoked_at')
                ->exists();
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->isLicenceRenewal() || $validator->errors()->has('expires_at') || ! $this->filled('expires_at')) {
                    return;
                }

                $current = app(DriverDocumentComplianceService::class)
                    ->currentDocument($this->user()->driver, DocumentType::DrivingLicence);

                // The same (or an older) licence again is not a renewal.
                if ($current?->expires_at !== null && Carbon::parse($this->input('expires_at'))->toDateString() <= $current->expires_at->toDateString()) {
                    $validator->errors()->add('expires_at', "Your licence on file is valid until {$current->expires_at->format('j M Y')}. Upload the renewed licence, which expires later.");
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document.max' => 'Document file size must not exceed 10MB.',
            'document.mimes' => 'Document must be a JPG, PNG, or PDF file.',
        ];
    }
}
