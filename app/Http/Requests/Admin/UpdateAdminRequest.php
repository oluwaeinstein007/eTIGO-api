<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->admin_role === AdminRole::SuperAdmin;
    }

    public function rules(): array
    {
        return [
            'admin_role' => ['sometimes', 'string', Rule::enum(AdminRole::class), Rule::notIn([AdminRole::SuperAdmin->value])],
        ];
    }

    public function messages(): array
    {
        return [
            'admin_role.not_in' => 'You cannot assign the super admin role.',
        ];
    }
}
