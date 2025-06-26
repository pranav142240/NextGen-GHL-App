<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GhlController extends Controller

{
    /**
     * Query GHL for a locationId by company email.
     *
     * @param  string $accessToken
     * @param  string $email
     * @return string|null  Location ID or null if not found / error
     */
    public function fetchLocationId(string $accessToken, string $email): ?string
    {
        try {
            $response = Http::withHeaders([
                'Accept'        => 'application/json',
                'Authorization' => "Bearer {$accessToken}",
                'Version'       => config('services.ghl.ghl_api_version'),
            ])
                ->get(config('services.ghl.ghl_api_base_url').'/locations/search', [
                    'email' => $email,
                ]);

            if ($response->failed()) {
                Log::error('GHL API request failed', [
                    'status'   => $response->status(),
                    'body'     => $response->body(),
                ]);
                return null;
            }

            $payload = $response->json();
            $locationId = $payload['locations'][0]['id'] ?? null;

            Log::info('Location ID retrieved', [
                'email'       => $email,
                'location_id' => $locationId,
            ]);

            return $locationId;
        } catch (\Throwable $e) {
            Log::error('Exception while retrieving location ID', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function getAccessTokenForLocation(string $companyId, string $locationId, string $accessToken): ?string
    {
        Log::info('Fetching location access token', [
            'companyId'  => $companyId,
            'locationId' => $locationId,
            'accessToken' => $accessToken,
        ]);
        try {
            $response = Http::asForm()
                ->withHeaders([
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Version'       => config('services.ghl.ghl_api_version'),
                ])
                ->post(config('services.ghl.ghl_api_base_url') . '/oauth/locationToken', [
                    'companyId'  => $companyId,
                    'locationId' => $locationId,
                ]);

            if ($response->failed()) {
                Log::error('Failed to retrieve location access token', [
                    'companyId'  => $companyId,
                    'locationId' => $locationId,
                    'response'   => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $locationAccessToken = $data['access_token'] ?? null;

            Log::info('Successfully fetched location access token', [
                'companyId'  => $companyId,
                'locationId' => $locationId,
            ]);

            return $locationAccessToken;
        } catch (\Throwable $e) {
            Log::error('Exception while fetching location access token', [
                'companyId' => $companyId,
                'locationId' => $locationId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }


    public function getAllCustomFields(
        string $locationAccessToken,
        string $locationId
    ): ?array {
        try {
            $endpoint = config('services.ghl.ghl_api_base_url')."/locations/{$locationId}/customFields";

            $response = Http::withHeaders([
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $locationAccessToken,
                'Version'       => config('services.ghl.ghl_api_version'),
            ])
                ->get($endpoint);

            if ($response->failed()) {
                Log::error('Failed to get custom fields', [
                    'locationId' => $locationId,
                    'status'     => $response->status(),
                    'response'   => $response->body(),
                ]);
                return null;
            }

            $customFields = $response->json();

            Log::info('Custom fields retrieved successfully', [
                'locationId' => $locationId,
                'fieldsCount' => count($customFields['customFields'] ?? []),
            ]);

            return $customFields;
        } catch (\Throwable $e) {
            Log::error('Exception while retrieving custom fields', [
                'locationId' => $locationId,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function createCustomField(
        string $locationAccessToken,
        string $locationId,
        string $fieldName = 'Custom Field',
        string $dataType = 'TEXT'
    ): ?array {
        try {
            $endpoint = config('services.ghl.ghl_api_base_url')."/locations/{$locationId}/customFields";

            $payload = [
                'name'            => $fieldName,
                'dataType'        => $dataType, // TEXT, FILE, DROPDOWN, etc.
                'placeholder'     => 'Placeholder Text',
                'acceptedFormat'  => ['.pdf', '.docx', '.jpeg'],
                'isMultipleFile'  => false,
                'maxNumberOfFiles' => 2,
                'textBoxListOptions' => [
                    ['label' => 'First', 'prefillValue' => '']
                ],
                'position'        => 0,
                'model'           => 'contact',
            ];

            $response = Http::withHeaders([
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $locationAccessToken,
                'Content-Type'  => 'application/json',
                'Version'       => config('services.ghl.ghl_api_version'),
            ])
                ->post($endpoint, $payload);

            if ($response->failed()) {
                Log::error('Failed to create file upload custom field', [
                    'locationId' => $locationId,
                    'status'     => $response->status(),
                    'response'   => $response->body(),
                ]);
                return null;
            }

            $createdField = $response->json();

            Log::info('File upload custom field created successfully', [
                'locationId' => $locationId,
                'fieldId'    => $createdField['id'] ?? null,
            ]);

            return $createdField;
        } catch (\Throwable $e) {
            Log::error('Exception while creating file upload custom field', [
                'locationId' => $locationId,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }


    /**
     * Upsert (create or update) a contact inside a specific GHL location.
     *
     * @param string $locationId           The location (sub-account) ID – **required by the API**
     * @param string $locationAccessToken  The short-lived token returned by /oauth/locationToken
     * @param array  $contactData          Any additional contact fields (firstName, phone, etc.)
     *
     * @return array|null  The contact payload returned by GHL or null on error
     */
    public function upsertContact(
        string $locationId,
        string $locationAccessToken,
        array  $contactData = []
    ): ?array {
        // ── Pre-flight validation ───────────────────────────────────────────────
        if (! $locationAccessToken) {
            Log::warning('upsertContact called without an access token');
            return null;
        }

        // Ensure the required locationId is present in the payload
        $payload = array_merge(['locationId' => $locationId], $contactData);

        try {
            $response = Http::withHeaders([
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $locationAccessToken,
                'Version'       => config('services.ghl.ghl_api_version'),
            ])
                ->post(config('services.ghl.ghl_api_base_url').'/contacts/upsert', $payload);

            if ($response->failed()) {
                Log::error('Failed to upsert contact', [
                    'locationId' => $locationId,
                    'status'     => $response->status(),
                    'body'       => $response->body(),
                ]);
                return null;
            }

            $body = $response->json();

            // GHL wraps the result under "contact"
            $contactId = data_get($body, 'contact.id');

            Log::info('Contact upserted', [
                'contactId'  => $contactId,
                'locationId' => $locationId,
            ]);

            return $body;
        } catch (\Throwable $e) {
            Log::error('Exception while upserting contact', [
                'locationId' => $locationId,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }
}
