<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\UpdatePreferencesRequest;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $saved = NotificationPreference::where('user_id', $user->id)
            ->get()
            ->keyBy(fn ($pref) => $pref->category->value);

        $defaults = config('notification.category_defaults', []);

        $preferences = collect(NotificationCategory::cases())->map(function (NotificationCategory $category) use ($saved, $defaults) {
            $pref = $saved->get($category->value);
            $defaultEnabled = $defaults[$category->value] ?? true;

            return [
                'category' => $category->value,
                'label' => $category->label(),
                'description' => $category->description(),
                'is_critical' => $category->isCritical(),
                'push_enabled' => $pref ? $pref->push_enabled : $defaultEnabled,
                'in_app_enabled' => $pref ? $pref->in_app_enabled : $defaultEnabled,
            ];
        });

        return response()->json(['data' => $preferences->values()]);
    }

    public function update(UpdatePreferencesRequest $request): JsonResponse
    {
        $user = $request->user();
        $updated = [];

        foreach ($request->validated('preferences') as $pref) {
            $category = NotificationCategory::from($pref['category']);

            if ($category->isCritical()) {
                $pref['push_enabled'] = true;
                $pref['in_app_enabled'] = true;
            }

            $record = NotificationPreference::updateOrCreate(
                ['user_id' => $user->id, 'category' => $category->value],
                [
                    'push_enabled' => $pref['push_enabled'],
                    'in_app_enabled' => $pref['in_app_enabled'],
                ],
            );

            $updated[] = [
                'category' => $category->value,
                'label' => $category->label(),
                'push_enabled' => $record->push_enabled,
                'in_app_enabled' => $record->in_app_enabled,
            ];
        }

        return response()->json([
            'message' => 'Notification preferences updated.',
            'data' => $updated,
        ]);
    }
}
