<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->admin_role === AdminRole::SuperAdmin;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'admin_role' => ['required', 'string', Rule::enum(AdminRole::class), Rule::notIn([AdminRole::SuperAdmin->value])],
        ];
    }

    public function messages(): array
    {
        return [
            'admin_role.not_in' => 'You cannot invite another super admin.',
        ];
    }
}
