<?php


namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyToken; 
use Illuminate\Support\Facades\Http; 
use Illuminate\Support\Facades\Log;

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
            return redirect('/')->with('error', 'Authorization failed: no code received.');
        }

        $response = $this->exchangeCodeForToken($code);

        // Add debugging
        Log::info('OAuth response:', $response);

        if (!isset($response['access_token'])) {
            Log::error('No access token in response:', $response);
            return redirect('/')->with('error', 'Failed to obtain access token.');
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
            return redirect('/')->with('error', 'Failed to save token.');
        }

        return redirect('/')->with('success', 'Authorization successful.');
    }

    private function exchangeCodeForToken($code)
    {
        try {
            $response = Http::asForm()->post('https://services.leadconnectorhq.com/oauth/token', [
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
}