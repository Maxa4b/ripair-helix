<?php

namespace App\Http\Controllers;

use App\Models\HelixSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = HelixSetting::query()->get()
            ->keyBy('key')
            ->map(fn ($setting) => $setting->value);

        return response()->json($settings);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $data = $request->validate([
            'value' => ['required'],
        ]);

        $setting = HelixSetting::updateOrCreate(
            ['key' => $key],
            ['value' => $data['value']]
        );

        return response()->json([
            'key' => $setting->key,
            'value' => $setting->value,
        ]);
    }
}
