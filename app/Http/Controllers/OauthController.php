<?php


namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Utils\Helper;
use Illuminate\Http\Request;
use App\Models\CompanyToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OauthController extends Controller
{
    public function initiate(Request $request)
    {
        $clientId    = config('services.ghl.client_id');
        $redirectUri = urlencode(config('services.ghl.redirect_uri'));
        $scopes = urlencode(config('services.ghl.scopes'));

        $url = config('services.ghl.marketplace_url') . "/oauth/chooselocation"
            . "?response_type=code"
            . "&client_id=$clientId"
            . "&redirect_uri=$redirectUri"
            . "&scope=$scopes"
            . "&user_type=Company";

        return redirect($url);
    }


    public function callback(Request $request)
    {
        $code = $request->input('code');

        if (!$code) {
            return redirect('https://app.gohighlevel.com/')->with('error', 'Authorization failed: no code received.');
        }

        $response = $this->exchangeCodeForToken($code);

        // Add debugging
        Log::info('OAuth response:', $response);

        if (!isset($response['access_token'])) {
            Log::error('No access token in response:', $response);
            return redirect('https://app.gohighlevel.com/')->with('error', 'Failed to obtain access token.');
        }

        try {
            // Save the token
            $token = CompanyToken::updateOrCreate(
                ['company_id' => $response['companyId'] ?? 'default'],
                [
                    'access_token'  => $response['access_token'],
                    'refresh_token' => $response['refresh_token'] ?? null,
                    'expires_at'    => now()->addSeconds($response['expires_in'] ?? 3600),
                    'token_type'    => $response['token_type'] ?? 'Bearer',
                    'active_status' => true,
                ]
            );

            Log::info('Token saved successfully:', ['token_id' => $token->id]);
        } catch (\Exception $e) {
            Log::error('Failed to save token:', ['error' => $e->getMessage()]);
            return redirect('https://app.gohighlevel.com/')->with('error', 'Failed to save token.');
        }

        return redirect('https://app.gohighlevel.com/')->with('success', 'Authorization successful.');
    }

    private function exchangeCodeForToken($code)
    {
        try {
            $response = Http::asForm()->post(config('services.ghl.ghl_api_base_url') . '/oauth/token', [
                'client_id' => config('services.ghl.client_id'),
                'client_secret' => config('services.ghl.client_secret'),
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => config('services.ghl.redirect_uri'),
            ]);

            if ($response->failed()) {
                Log::error('OAuth token exchange failed', ['response' => $response->body()]);
                return [];
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('OAuth token exchange error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public static function uninstall(string $companyId)
    {
        try {
            // Use helper method to deactivate the token
            $deactivated = Helper::deactivateCompanyToken($companyId);

            if (!$deactivated) {
                Log::warning("Uninstall failed: Could not deactivate token for company: {$companyId}");
                return response()->json(['error' => 'Failed to deactivate company token'], 500);
            }

            Log::info("App uninstalled successfully for company: {$companyId}");

            return response()->json([
                'message' => 'App uninstalled successfully',
                'company_id' => $companyId,
                'status' => 'deactivated'
            ], 200);
        } catch (\Exception $e) {
            Log::error("Failed to uninstall app for company", [
                'error' => $e->getMessage(),
                'company_id' => $companyId
            ]);

            return response()->json(['error' => 'Uninstall failed'], 500);
        }
    }

    public static function install(string $companyId)
    {
        try {
            // Use helper method to activate the token
            $activated = Helper::activateCompanyToken($companyId);

            if (!$activated) {
                Log::warning("Install failed: Could not activate token for company: {$companyId}");
                return response()->json(['error' => 'Failed to activate company token'], 500);
            }

            Log::info("App installed successfully for company: {$companyId}");

            return response()->json([
                'message' => 'App installed successfully',
                'company_id' => $companyId,
                'status' => 'activated'
            ], 200);
        } catch (\Exception $e) {
            Log::error("Failed to install app for company", [
                'error' => $e->getMessage(),
                'company_id' => $companyId
            ]);

            return response()->json(['error' => 'Install failed'], 500);
        }
    }

}
