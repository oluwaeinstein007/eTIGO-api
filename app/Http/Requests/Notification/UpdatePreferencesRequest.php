<?php

namespace App\Http\Requests\Notification;

use App\Enums\NotificationCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePreferencesRequest extends FormRequest
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
        $categories = array_column(NotificationCategory::cases(), 'value');

        return [
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*.category' => ['required', 'string', Rule::in($categories)],
            'preferences.*.push_enabled' => ['required', 'boolean'],
            'preferences.*.in_app_enabled' => ['required', 'boolean'],
        ];
    }
}
