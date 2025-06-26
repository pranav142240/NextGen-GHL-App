<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\GhlController;
use App\Http\Controllers\OauthController;
use App\Models\CompanyToken;
use App\utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\JsonResponse;

class WebhookController extends Controller
{
    private const FIELD_PROCESSING_BATCH_SIZE = 50;
    private const BATCH_PROCESSING_DELAY = 1; // seconds
    private const CACHE_TTL = 300; // 5 minutes

    private const DEFAULT_GHL_FIELDS = [
        'contact_id',
        'first_name',
        'last_name',
        'full_name',
        'email',
        'phone',
        'tags',
        'address1',
        'city',
        'state',
        'postal_code',
        'country',
        'timezone',
        'date_created',
        'contact_source',
        'full_address',
        'contact_type',
        'location',
        'triggerData',
        'contact',
        'attributionSource',
        'Card authorization',
        'workflow'
    ];

    public function processHandle(Request $request): JsonResponse
    {
        try {
            $payload = $request->all();

            Log::info('Webhook received', [
                'payload' => $payload,
                'payload_count' => count($payload)
            ]);

            // Extract required fields from payload
            $type = $payload['type'] ?? null;
            $companyId = $payload['companyId'] ?? null;
            $locationId = $payload['locationId'] ?? null;
            $webhookId = $payload['webhookId'] ?? null;

            // Validate required fields
            if (!$type) {
                Log::warning('Webhook missing type field', ['payload' => $payload]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Webhook type is required'
                ], 400);
            }

            // Check if we have either companyId or locationId
            if (!$companyId && !$locationId) {
                Log::warning('Webhook missing both companyId and locationId', ['payload' => $payload]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Either Company ID or Location ID is required'
                ], 400);
            }

            // If locationId exists (and no companyId), just return success (no processing needed)
            if ($locationId && !$companyId) {
                Log::info('Location-level webhook received, returning success', [
                    'type' => $type,
                    'locationId' => $locationId,
                    'webhookId' => $webhookId
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Location webhook acknowledged'
                ], 200);
            }

            // If companyId exists, process the install/uninstall
            if ($companyId) {
                // Process based on type
                switch (strtoupper($type)) {
                    case 'INSTALL':
                        Log::info('Processing INSTALL webhook', [
                            'companyId' => $companyId,
                            'webhookId' => $webhookId
                        ]);

                        $result = OauthController::install($companyId);
                        break;

                    case 'UNINSTALL':
                        Log::info('Processing UNINSTALL webhook', [
                            'companyId' => $companyId,
                            'webhookId' => $webhookId
                        ]);

                        $result = OauthController::uninstall($companyId);
                        break;

                    default:
                        Log::warning('Unknown webhook type received', [
                            'type' => $type,
                            'companyId' => $companyId
                        ]);

                        return response()->json([
                            'status' => 'error',
                            'message' => 'Unknown webhook type: ' . $type
                        ], 400);
                }

                return $result;
            }

            // This should not be reached, but just in case
            Log::warning('Unexpected webhook state', [
                'type' => $type,
                'companyId' => $companyId,
                'locationId' => $locationId
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Unable to process webhook'
            ], 400);
        } catch (\Exception $e) {
            Log::error('Webhook processing failed', [
                'error' => $e->getMessage(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process webhook: ' . $e->getMessage()
            ], 500);
        }
    }

    public function handle(Request $request): JsonResponse
    {
        set_time_limit(300);

        try {


            $payload = $request->all();

            Log::info('Webhook received', ['payload_count' => count($payload)]);
            Log::info('Webhook received:', $payload);

            // Validate and get tokens
            $tokens = $this->getValidTokens($payload);
            if ($tokens instanceof JsonResponse) {
                return $tokens;
            }

            ['locationId' => $locationId, 'locationAccessToken' => $locationAccessToken] = $tokens;

            // Process custom fields efficiently
            $customFieldsResult = $this->processCustomFields($payload, $locationAccessToken, $locationId);

            // Prepare and upsert contact
            $contactData = $this->prepareContactData($payload, $customFieldsResult);
            $contactResult = $this->upsertContact($locationId, $locationAccessToken, $contactData);

            if (!$contactResult) {
                throw new \Exception('Contact upsert failed');
            }

            Log::info('Webhook processed successfully', [
                'contact_id' => $contactResult['contact']['id'] ?? 'unknown',
                'email' => $contactData['email'],
                'custom_fields_created' => $customFieldsResult['created_count']
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Contact and custom fields processed successfully',
                'data' => [
                    'contact_id' => $contactResult['contact']['id'] ?? null,
                    'custom_fields_created' => $customFieldsResult['created_count'],
                    'location_id' => $locationId
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Webhook processing failed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process webhook: ' . $e->getMessage()
            ], 500);
        }
    }


    private function getValidTokens(array $payload): array|JsonResponse
    {
        // Get active company token without caching
        $companyToken = CompanyToken::where('active_status', true)->first();

        if (!$companyToken) {
            Log::error('No active company token found');
            return response()->json(['error' => 'No active company token found'], 404);
        }

        $companyId = $companyToken->company_id;
        $validAccessToken = Helper::getValidAccessToken($companyId);

        if (!$validAccessToken) {
            Log::error('Failed to get valid access token', ['company_id' => $companyId]);
            return response()->json(['error' => 'Failed to get valid access token'], 401);
        }

        $businessEmail = $payload['Business Email'] ?? null;
        if (!$businessEmail) {
            Log::error('No business email found in webhook payload');
            return response()->json(['error' => 'Business email required'], 400);
        }

        $ghlController = new GhlController();

        // Fetch location ID without caching
        $locationId = $ghlController->fetchLocationId($validAccessToken, $businessEmail);

        if (!$locationId) {
            Log::error('Failed to fetch location ID', ['business_email' => $businessEmail]);
            return response()->json(['error' => 'Location not found for business email'], 404);
        }

        // Generate fresh location access token every time
        $locationAccessToken = $ghlController->getAccessTokenForLocation($companyId, $locationId, $validAccessToken);
        if (!$locationAccessToken) {
            Log::error('Failed to get location access token', [
                'company_id' => $companyId,
                'location_id' => $locationId
            ]);
            return response()->json(['error' => 'Failed to get location access token'], 401);
        }

        Log::info('Tokens obtained successfully', ['location_id' => $locationId]);

        return [
            'locationId' => $locationId,
            'locationAccessToken' => $locationAccessToken
        ];
    }

    private function processCustomFields(array $payload, string $locationAccessToken, string $locationId): array
    {
        $customFieldsToProcess = array_filter(
            array_keys($payload),
            fn($fieldName) => !in_array($fieldName, self::DEFAULT_GHL_FIELDS)
        );

        if (empty($customFieldsToProcess)) {
            return ['created_fields' => [], 'existing_fields' => [], 'created_count' => 0];
        }

        Log::info('Processing custom fields', ['total_fields' => count($customFieldsToProcess)]);

        $cacheKey = "custom_fields_{$locationId}";
        $allCustomFields = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($locationAccessToken, $locationId) {
            $ghlController = new GhlController();
            return $ghlController->getAllCustomFields($locationAccessToken, $locationId);
        });

        $mappedPayload = $this->createFieldMappings($customFieldsToProcess);
        $existingFieldKeys = $this->extractExistingFieldKeys($allCustomFields);
        $unmatchedFields = $this->findUnmatchedFields($mappedPayload, $existingFieldKeys);

        Log::info('Field matching completed', [
            'total_existing' => count($existingFieldKeys),
            'unmatched_fields' => count($unmatchedFields)
        ]);

        $createdCustomFields = $this->createCustomFieldsBatch($unmatchedFields, $locationAccessToken, $locationId);

        if (!empty($createdCustomFields)) {
            Cache::forget($cacheKey);
        }

        return [
            'created_fields' => $createdCustomFields,
            'existing_fields' => $allCustomFields,
            'mapped_payload' => $mappedPayload,
            'created_count' => count($createdCustomFields)
        ];
    }

    private function createFieldMappings(array $fieldNames): array
    {
        $mappedPayload = [];

        foreach ($fieldNames as $fieldName) {
            $contactField = $this->normalizeFieldName($fieldName);
            $mappedPayload[$fieldName] = "{{ contact.{$contactField} }}";
        }

        return $mappedPayload;
    }

    private function normalizeFieldName(string $fieldName): string
    {
        $contactField = strtolower($fieldName);
        $contactField = str_replace([' ', '"', '/', '(', ')', '-', '?', ':'], '_', $contactField);
        $contactField = preg_replace('/[^a-z0-9_]/', '', $contactField);
        $contactField = preg_replace('/_+/', '_', $contactField);

        return trim($contactField, '_');
    }

    private function extractExistingFieldKeys(array $allCustomFields): array
    {
        $existingFieldKeys = [];

        if (isset($allCustomFields['customFields']) && is_array($allCustomFields['customFields'])) {
            foreach ($allCustomFields['customFields'] as $field) {
                if (isset($field['fieldKey'])) {
                    $fieldKey = str_replace('contact.', '', $field['fieldKey']);
                    $existingFieldKeys[] = $fieldKey;
                }
            }
        }

        return $existingFieldKeys;
    }

    private function findUnmatchedFields(array $mappedPayload, array $existingFieldKeys): array
    {
        $unmatchedFields = [];
        $matchedCount = 0;

        foreach ($mappedPayload as $fieldName => $contactField) {
            $possibleKeys = $this->generateFieldKeyVariations($fieldName);

            $found = false;
            foreach ($possibleKeys as $possibleKey) {
                if (in_array($possibleKey, $existingFieldKeys)) {
                    $found = true;
                    $matchedCount++;
                    break;
                }
            }

            if (!$found) {
                $unmatchedFields[] = $fieldName;
            }
        }

        // Only log unmatched fields if there are any
        if (!empty($unmatchedFields)) {
            Log::info('Unmatched fields found', [
                'matched_count' => $matchedCount,
                'unmatched_count' => count($unmatchedFields),
                'unmatched_fields' => array_slice($unmatchedFields, 0, 5) // Log first 5 only
            ]);
        }

        return $unmatchedFields;
    }

    private function generateFieldKeyVariations(string $fieldName): array
    {
        $variations = [];

        $variations[] = $this->normalizeFieldName($fieldName);

        $clean = strtolower(preg_replace('/[^a-zA-Z0-9\s]/', '', $fieldName));
        $variations[] = preg_replace('/\s+/', '_', trim($clean));

        $variations[] = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $fieldName));

        $ghlStyle = strtolower($fieldName);

        if (strpos($fieldName, '/') !== false) {
            $noSlashes = str_replace('/', '', $ghlStyle);
            $variations[] = preg_replace('/\s+/', '_', trim($noSlashes));
            $variations[] = preg_replace('/[^a-zA-Z0-9]/', '', $ghlStyle);
        }

        if (strpos($fieldName, '(') !== false) {
            $noParens = preg_replace('/\([^)]*\)/', '', $fieldName);
            $variations[] = $this->normalizeFieldName(trim($noParens));
        }

        if (strpos($fieldName, 'Drill-Down') !== false) {
            $drilldown = str_replace('Drill-Down', 'drilldown', $fieldName);
            $variations[] = $this->normalizeFieldName($drilldown);
        }

        if (strpos($fieldName, ':') !== false) {
            $noColons = str_replace(':', '', $fieldName);
            $variations[] = $this->normalizeFieldName($noColons);
        }

        $patterns = [
            str_replace(['/', '\\'], '', $ghlStyle),
            preg_replace('/[^\w\s]/', '', $ghlStyle),
            preg_replace('/[^a-z0-9]/', '', $ghlStyle)
        ];

        foreach ($patterns as $pattern) {
            $normalized = preg_replace('/\s+/', '_', trim($pattern));
            $normalized = trim($normalized, '_');
            $normalized = preg_replace('/_+/', '_', $normalized);
            if (!empty($normalized)) {
                $variations[] = $normalized;
            }
        }

        return array_unique(array_filter($variations));
    }

    private function createCustomFieldsBatch(array $unmatchedFields, string $locationAccessToken, string $locationId): array
    {
        if (empty($unmatchedFields)) {
            return [];
        }

        $createdCustomFields = [];
        $ghlController = new GhlController();
        $unmatchedBatches = array_chunk($unmatchedFields, self::FIELD_PROCESSING_BATCH_SIZE);

        Log::info('Creating custom fields', [
            'total_fields' => count($unmatchedFields),
            'batches' => count($unmatchedBatches)
        ]);

        foreach ($unmatchedBatches as $batchIndex => $batch) {
            foreach ($batch as $fieldName) {
                try {
                    $customFieldResult = $ghlController->createCustomField(
                        $locationAccessToken,
                        $locationId,
                        $fieldName,
                        'TEXT'
                    );

                    if ($customFieldResult && isset($customFieldResult['customField']['fieldKey'])) {
                        $createdCustomFields[$fieldName] = $customFieldResult;
                    }
                } catch (\Exception $e) {
                    if (!str_contains($e->getMessage(), 'already exists')) {
                        Log::warning('Failed to create custom field', [
                            'field' => $fieldName,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            if ($batchIndex < count($unmatchedBatches) - 1) {
                sleep(self::BATCH_PROCESSING_DELAY);
            }
        }

        if (!empty($createdCustomFields)) {
            Log::info('Custom fields created successfully', ['count' => count($createdCustomFields)]);
        }

        return $createdCustomFields;
    }

    private function prepareContactData(array $payload, array $customFieldsResult): array
    {
        $contactData = [
            'firstName' => $payload['first_name'] ?? ($payload['Rep First name'] ?? ''),
            'lastName' => $payload['last_name'] ?? ($payload['Rep Last name'] ?? ''),
            'email' => $payload['email'] ?? ($payload['Business Email'] ?? ($payload['Representative Email'] ?? '')),
            'phone' => $payload['phone'] ?? ($payload['Business Phone Number'] ?? ($payload['Representative Phone Number'] ?? '')),
            'address1' => $payload['address1'] ?? ($payload['Business Address'] ?? ''),
            'city' => $payload['Business City'] ?? '',
            'state' => $payload['Business State'] ?? '',
            'country' => $payload['country'] ?? ($payload['Business Country'] ?? ''),
            'postalCode' => $payload['Business Postal Code'] ?? '',
            'timezone' => $payload['timezone'] ?? '',
            'companyName' => $payload['Gym Name'] ?? ($payload['Legal Business Name '] ?? ''),
            'website' => $payload['Business website'] ?? '',
            'customFields' => []
        ];

        // Add newly created custom fields
        foreach ($customFieldsResult['created_fields'] as $fieldName => $customField) {
            if (isset($customField['customField']['fieldKey']) && array_key_exists($fieldName, $payload)) {
                $fieldKey = $customField['customField']['fieldKey'];
                $cleanFieldKey = str_replace('contact.', '', $fieldKey);
                $fieldValue = $this->formatFieldValue($payload[$fieldName]);

                $contactData['customFields'][] = [
                    'key' => $cleanFieldKey,
                    'field_value' => $fieldValue
                ];
            }
        }

        // Add existing custom fields
        $this->addExistingCustomFields($contactData, $payload, $customFieldsResult);

        Log::info('Contact data prepared', [
            'email' => $contactData['email'],
            'total_custom_fields' => count($contactData['customFields']),
            'newly_created_fields' => count($customFieldsResult['created_fields'])
        ]);

        return $contactData;
    }

    private function addExistingCustomFields(array &$contactData, array $payload, array $customFieldsResult): void
    {
        if (!isset($customFieldsResult['mapped_payload']) || !isset($customFieldsResult['existing_fields']['customFields'])) {
            return;
        }

        foreach ($customFieldsResult['mapped_payload'] as $fieldName => $contactFieldTemplate) {
            if (isset($customFieldsResult['created_fields'][$fieldName])) {
                continue;
            }

            $fieldKey = str_replace(['{{ contact.', ' }}'], '', $contactFieldTemplate);
            $fullFieldKey = 'contact.' . $fieldKey;

            foreach ($customFieldsResult['existing_fields']['customFields'] as $existingField) {
                if (isset($existingField['fieldKey']) && $existingField['fieldKey'] === $fullFieldKey) {
                    if (array_key_exists($fieldName, $payload)) {
                        $cleanFieldKey = str_replace('contact.', '', $existingField['fieldKey']);
                        $fieldValue = $this->formatFieldValue($payload[$fieldName]);

                        $contactData['customFields'][] = [
                            'key' => $cleanFieldKey,
                            'field_value' => $fieldValue
                        ];
                    }
                    break;
                }
            }
        }
    }

    private function formatFieldValue($value): string
    {
        if (is_array($value)) {
            return implode(', ', $value);
        }

        return (string) ($value ?? '');
    }

    private function upsertContact(string $locationId, string $locationAccessToken, array $contactData): ?array
    {
        try {
            $ghlController = new GhlController();
            $contactResult = $ghlController->upsertContact($locationId, $locationAccessToken, $contactData);

            if ($contactResult) {
                Log::info('Contact upserted successfully', [
                    'contact_id' => $contactResult['contact']['id'] ?? 'unknown',
                    'email' => $contactData['email']
                ]);
            } else {
                Log::error('Failed to upsert contact', ['email' => $contactData['email']]);
            }

            return $contactResult;
        } catch (\Exception $e) {
            Log::error('Exception during contact upsert', [
                'error' => $e->getMessage(),
                'email' => $contactData['email']
            ]);
            throw $e;
        }
    }
}
