<?php

namespace App\Utils;

use App\Models\CompanyToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Helper
{
    /**
     * Check if the token is expired for a company, and refresh if needed.
     *
     * @param  string $companyId
     * @return string|null  Returns a valid access token, or null if failed
     */
    public static function getValidAccessToken(string $companyId): ?string
    {
        $token = CompanyToken::where('company_id', $companyId)->first();

        log::info("Checking token for company: {$companyId}", [
            'token' => $token ? 'exists' : 'not found',
        ]);

        if (!$token) {
            Log::warning("No token found for company: {$companyId}");
            return null;
        }

        // If no expiry is set, assume token is valid
        if (!$token->expires_at) {
            return $token->access_token;
        }

        // If expired → try to refresh
        if (Carbon::now()->isAfter(Carbon::parse($token->expires_at))) {
            return self::refreshToken($companyId);
        }

        return $token->access_token;
    }

    /**
     * Refresh the OAuth token for the given company.
     *
     * @param  string $companyId
     * @return string|null  The new access token, or null on failure
     */
    public static function refreshToken(string $companyId): ?string
    {
        try {
            $token = CompanyToken::where('company_id', $companyId)->first();

            if (!$token || empty($token->refresh_token)) {
                Log::error("No refresh token found for company: {$companyId}");
                return null;
            }

            $response = Http::asForm()->post(
                'https://services.leadconnectorhq.com/oauth/token',
                [
                    'client_id'     => config('services.ghl.client_id'),
                    'client_secret' => config('services.ghl.client_secret'),
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $token->refresh_token,
                    'user_type'     => 'Company',
                    'redirect_uri'  => config('services.ghl.redirect_uri'),
                ]
            );

            if ($response->successful()) {
                $data = $response->json();

                $token->update([
                    'access_token'  => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? $token->refresh_token,
                    'expires_at'    => Carbon::now()->addSeconds($data['expires_in'] ?? 3600),
                    'token_type'    => $data['token_type'] ?? 'Bearer',
                    'active_status' => true,
                ]);

                Log::info("Token refreshed successfully for company: {$companyId}");
                return $data['access_token'];
            }

            Log::error("Failed to refresh token for company: {$companyId}", [
                'response' => $response->body(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error("Exception while refreshing token for company: {$companyId}", [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
