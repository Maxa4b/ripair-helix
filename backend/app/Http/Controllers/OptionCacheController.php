<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class OptionCacheController extends Controller
{
    public function refresh(): JsonResponse
    {
        $baseUrl = rtrim(config('services.ripair.base_url') ?? '', '/');
        $token = config('services.ripair.options_cache_token');

        if ($baseUrl === '' || $token === null || $token === '') {
            return response()->json([
                'message' => 'ripair_cache_endpoint_not_configured',
            ], 500);
        }

        try {
            $response = Http::timeout(10)
                ->asForm()
                ->post($baseUrl . '/api/options_cache_bump.php', [
                    'token' => $token,
                ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'ripair_site_unreachable',
                'error' => $exception->getMessage(),
            ], 502);
        }

        $payload = $this->decodeResponse($response);

        if ($response->failed()) {
            return response()->json([
                'message' => 'ripair_site_error',
                'status' => $response->status(),
                'body' => $payload ?? $response->body(),
            ], $response->status() ?: 500);
        }

        return response()->json([
            'message' => 'options_cache_refreshed',
            'version' => $payload['version'] ?? null,
            'updated_at' => $payload['updated_at'] ?? null,
        ]);
    }

    /**
     * Decode the remote response without breaking if the payload is not JSON.
     */
    private function decodeResponse(Response $response): ?array
    {
        try {
            $decoded = $response->json();

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $exception) {
            return null;
        }
    }
}
