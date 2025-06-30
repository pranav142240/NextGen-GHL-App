<?php

namespace App\Utils;

use App\Models\CompanyToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Helper
{

    public static function getValidAccessToken($companyId)
    {
        // Grab the whole model row so we can read expires_at
        $tokenRow = CompanyToken::where('company_id', $companyId)->first();

        if (!$tokenRow) {
            return null;
        }

        // ----- key change: pass the model, not the raw token string -----
        if (self::isTokenExpired($tokenRow)) {
            Log::info('Token expired, attempting refresh', ['company_id' => $companyId]);

            $refreshed = self::refreshToken($companyId);

            if ($refreshed) {
                return $refreshed;
            }

            Log::error('Failed to refresh expired token', ['company_id' => $companyId]);
            return null;
        }

        return $tokenRow->access_token;
    }

    /**
     * Decide whether the access token is expired *using the expires_at column*.
     * We subtract 5 minutes as a safety buffer exactly like before.
     */
    private static function isTokenExpired(CompanyToken $tokenRow): bool
    {
        try {

            $expiresAt = Carbon::parse($tokenRow->expires_at)->timezone('UTC');

            // 5-minute buffer -> call isPast() after shifting back 5 minutes
            return $expiresAt->subMinutes(5)->isPast();
        } catch (\Throwable $e) {
            Log::error('Error checking token expiry', ['error' => $e->getMessage()]);
            return true;   
        }
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
                config('services.ghl.ghl_api_base_url') . '/oauth/token',
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

    public static function toggleCompanyTokenStatus(string $companyId): bool
    {
        try {
            $token = CompanyToken::where('company_id', $companyId)->first();

            if (!$token) {
                Log::warning("No token found for company: {$companyId}");
                return false;
            }

            $token->active_status = !$token->active_status;
            $saved = $token->save();

            Log::info("Token status toggled for company: {$companyId}", [
                'new_status' => $token->active_status ? 'active' : 'inactive'
            ]);

            return $saved;
        } catch (\Throwable $e) {
            Log::error("Failed to toggle token status for company: {$companyId}", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Deactivate a company token
     *
     * @param string $companyId
     * @return bool
     */
    public static function deactivateCompanyToken(string $companyId): bool
    {
        try {
            $token = CompanyToken::where('company_id', $companyId)->first();

            if (!$token) {
                Log::warning("No token found for company: {$companyId}");
                return false;
            }

            $token->active_status = false;
            $saved = $token->save();

            Log::info("Token deactivated for company: {$companyId}");
            return $saved;
        } catch (\Throwable $e) {
            Log::error("Failed to deactivate token for company: {$companyId}", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public static function activateCompanyToken(string $companyId): bool
    {
        try {
            $token = CompanyToken::where('company_id', $companyId)->first();

            if (!$token) {
                Log::warning("No token found for company: {$companyId}");
                return false;
            }

            $token->active_status = true;
            $saved = $token->save();

            Log::info("Token activated for company: {$companyId}");
            return $saved;
        } catch (\Throwable $e) {
            Log::error("Failed to activate token for company: {$companyId}", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
