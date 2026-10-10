<?php

namespace App\Http\Requests\Admin\Fleet;

use Illuminate\Foundation\Http\FormRequest;

class ExcuseRemittanceDayRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:500', 'in:approved_leave,vehicle_downtime,low_demand,payment_failure,other'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
