<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\GhlController;
use App\Models\CompanyToken;
use App\utils\Helper;

use Psy\TabCompletion\Matcher\FunctionDefaultParametersMatcher;
use function Pest\Laravel\get;

class TestController extends Controller
{

    public function handle(Request $request)
    {
        set_time_limit(300);

        $payload = $request->all();

        Log::info('Webhook received:', $payload);

        $companyToken = CompanyToken::where('active_status', true)->first();

        if (!$companyToken) {
            Log::error('No active company token found in database');
            return response()->json(['error' => 'No active company token found'], 404);
        }

        $companyId = $companyToken->company_id;
        $validAccessToken = Helper::getValidAccessToken($companyId);

        if (!$validAccessToken) {
            Log::error('Failed to get valid access token for company ID: ' . $companyId);
            return response()->json(['error' => 'Failed to get valid access token'], 401);
        }

        Log::info('Valid access token obtained: ' . $validAccessToken);
        $businessEmail = $payload['Business Email'] ?? null;

        if (!$businessEmail) {
            Log::error('No business email found in webhook payload');
            return response()->json(['error' => 'Business email required'], 400);
        }

        $ghlController = new GhlController();
        $locationId = $ghlController->fetchLocationId($validAccessToken, $businessEmail);

        if (!$locationId) {
            Log::error('Failed to fetch location ID for business email: ' . $businessEmail);
            return response()->json(['error' => 'Location not found for business email'], 404);
        }

        Log::info('New location ID found: ' . $locationId, ['business_email' => $businessEmail]);

        $locationAccessToken = $ghlController->getAccessTokenForLocation($companyId, $locationId, $validAccessToken);

        if (!$locationAccessToken) {
            Log::error('Failed to get location access token', [
                'companyId' => $companyId,
                'locationId' => $locationId
            ]);
            return response()->json(['error' => 'Failed to get location access token'], 401);
        }

        Log::info('Location access token obtained successfully', ['locationAccessToken' => $locationAccessToken]);

        $defaultGhlFields = [
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
            'workflow',
            'customData'
        ];

        $customFieldsToCreate = [];

        foreach ($payload as $fieldName => $fieldValue) {
            // Skip ONLY the core default GHL fields
            if (in_array($fieldName, $defaultGhlFields)) {
                continue;
            }

            // Add ALL remaining fields as TEXT (including Associated, Calendly, null, empty)
            $customFieldsToCreate[$fieldName] = [
                'name' => $fieldName,
                'dataType' => 'TEXT'
            ];
        }

        Log::info('Custom fields to create (including Associated & Calendly)', [
            'total_fields' => count($customFieldsToCreate),
            'fields' => array_keys($customFieldsToCreate)
        ]);

        // Create mapped payload with transformation rules
        $mappedPayload = [];


        foreach ($customFieldsToCreate as $field) {
            $fieldName = $field['name'] ?? $field;

            // Convert field name to lowercase contact field format
            $contactField = strtolower($fieldName);
            $contactField = str_replace([' ', '"', '/', '(', ')', '-', '?', ':'], '_', $contactField);
            $contactField = preg_replace('/[^a-z0-9_]/', '', $contactField); // Remove special chars
            $contactField = preg_replace('/_+/', '_', $contactField); // Replace multiple underscores
            $contactField = trim($contactField, '_'); // Remove leading/trailing underscores

            // Store as field: converted field format
            $mappedPayload[$fieldName] = "{{ contact." . $contactField . " }}";
        }


        // Log the mapped payload
        Log::info('Mapped payload created', [
            'total_mapped_fields' => count($mappedPayload),
            'mapped_fields' => $mappedPayload
        ]);

        // Get all custom fields from HubSpot
        $allCustomFields = $ghlController->getAllCustomFields($locationAccessToken, $locationId);

        // Log the retrieved custom fields
        Log::info('Retrieved all custom fields from HubSpot', [
            'total_retrieved_fields' => count($allCustomFields),
            'retrieved_fields' => $allCustomFields
        ]);



        // Extract field keys from allCustomFields - FIX: Extract just the field name part
        $existingFieldKeys = [];
        foreach ($allCustomFields['customFields'] as $field) {
            if (isset($field['fieldKey'])) {
                // Extract just the field name part (remove 'contact.' prefix)
                $fieldKey = str_replace('contact.', '', $field['fieldKey']);
                $existingFieldKeys[] = $fieldKey;
            }
        }

        // Find unmatched fields (fields in mappedPayload that don't exist in HubSpot)
        $unmatchedFields = [];
        foreach ($mappedPayload as $fieldName => $contactField) {
            // Extract the contact field key from the template (remove {{ contact. and }})
            $fieldKey = str_replace(['{{ contact.', ' }}'], '', $contactField);

            if (!in_array($fieldKey, $existingFieldKeys)) {
                $unmatchedFields[] = $fieldName; // Only save the field name (key)
            }
        }

        // Log unmatched fields
        Log::info('Unmatched fields found', [
            'total_unmatched' => count($unmatchedFields),
            'unmatched_fields' => $unmatchedFields
        ]);




        // Create custom fields in GHL only for unmatched fields
        $createdCustomFields = [];
        $fieldKeyMapping = []; // This will store original_key => fieldKey mapping

        $batchSize = 50;
        $unmatchedBatches = array_chunk($unmatchedFields, $batchSize);



        foreach ($unmatchedBatches as $batch) {
            foreach ($batch as $fieldName) {
                try {
                    Log::info('Creating custom field for unmatched field', [
                        'field' => $fieldName,
                        'type' => 'TEXT'
                    ]);

                    $customFieldResult = $ghlController->createCustomField(
                        $locationAccessToken,
                        $locationId,
                        $fieldName,
                        'TEXT'
                    );

                    // Fix: Check for fieldKey in the correct nested structure
                    if ($customFieldResult && isset($customFieldResult['customField']['fieldKey'])) {
                        $createdCustomFields[$fieldName] = $customFieldResult;

                        // Map original field name to new fieldKey
                        $fieldKeyMapping[$fieldName] = $customFieldResult['customField']['fieldKey'];

                        Log::info('Custom field created successfully', [
                            'original_field' => $fieldName,
                            'fieldKey' => $customFieldResult['customField']['fieldKey'],
                            'type' => 'TEXT',
                            'result' => $customFieldResult
                        ]);
                    } else {
                        Log::warning('Failed to create custom field or missing fieldKey', [
                            'field' => $fieldName,
                            'result' => $customFieldResult
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Error creating custom field', [
                        'field' => $fieldName,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            sleep(1);
        }



        // ----------upsert contact
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

        // Add custom field values to contact data using fieldKey instead of id
        foreach ($createdCustomFields as $fieldName => $customField) {
            if (isset($customField['customField']['fieldKey']) && array_key_exists($fieldName, $payload)) {
                // Remove 'contact.' prefix for GHL API
                $fieldKey = $customField['customField']['fieldKey'];
                $cleanFieldKey = str_replace('contact.', '', $fieldKey);

                // Convert array values to string
                $fieldValue = $payload[$fieldName] ?? '';
                if (is_array($fieldValue)) {
                    $fieldValue = implode(', ', $fieldValue); // Convert array to comma-separated string
                }

                $contactData['customFields'][] = [
                    'key' => $cleanFieldKey, // Remove contact. prefix
                    'field_value' => $fieldValue // Use converted field value
                ];
            }
        }

        // Also add existing custom fields (matched ones) to the contact data
        foreach ($mappedPayload as $fieldName => $contactFieldTemplate) {
            // Skip if this field was already processed in createdCustomFields
            if (isset($createdCustomFields[$fieldName])) {
                continue;
            }

            // For existing fields, we need to find the fieldKey from allCustomFields
            $fieldKey = str_replace(['{{ contact.', ' }}'], '', $contactFieldTemplate);
            $fullFieldKey = 'contact.' . $fieldKey;

            // Find the fieldKey from existing custom fields
            foreach ($allCustomFields['customFields'] as $existingField) {
                if (isset($existingField['fieldKey']) && $existingField['fieldKey'] === $fullFieldKey) {
                    if (array_key_exists($fieldName, $payload)) { // Use array_key_exists to include null values
                        // Remove 'contact.' prefix for GHL API
                        $cleanFieldKey = str_replace('contact.', '', $existingField['fieldKey']);

                        // Convert array values to string
                        $fieldValue = $payload[$fieldName] ?? '';
                        if (is_array($fieldValue)) {
                            $fieldValue = implode(', ', $fieldValue); // Convert array to comma-separated string
                        }

                        $contactData['customFields'][] = [
                            'key' => $cleanFieldKey, // Remove contact. prefix
                            'field_value' => $fieldValue // Use converted field value
                        ];
                    }
                    break;
                }
            }
        }

        // Add detailed logging for field processing breakdown
        Log::info('Field processing breakdown', [
            'total_payload_fields' => count($payload),
            'created_custom_fields' => count($createdCustomFields),
            'mapped_payload_fields' => count($mappedPayload),
            'final_custom_fields_count' => count($contactData['customFields']),
            'fields_with_null_values' => count(array_filter($payload, function ($value) {
                return is_null($value);
            })),
            'newly_created_processed' => count(array_filter($createdCustomFields, function ($fieldName) use ($payload) {
                return array_key_exists($fieldName, $payload);
            }, ARRAY_FILTER_USE_KEY)),
            'existing_fields_processed' => count($contactData['customFields']) - count(array_filter($createdCustomFields, function ($fieldName) use ($payload) {
                return array_key_exists($fieldName, $payload);
            }, ARRAY_FILTER_USE_KEY))
        ]);

        // Log the complete contact data for verification
        Log::info('Contact data prepared for upsert - VERIFICATION LOG', [
            'contact_email' => $contactData['email'],
            'contact_basic_info' => [
                'firstName' => $contactData['firstName'],
                'lastName' => $contactData['lastName'],
                'email' => $contactData['email'],
                'phone' => $contactData['phone'],
                'companyName' => $contactData['companyName']
            ],
            'total_custom_fields' => count($contactData['customFields']),
            'custom_fields_breakdown' => [
                'newly_created_fields' => count($createdCustomFields),
                'existing_matched_fields' => count($contactData['customFields']) - count($createdCustomFields)
            ]
        ]);

        // Attempt to upsert the contact in GHL
        try {
            // Upsert contact in GHL
            $contactResult = $ghlController->upsertContact($locationId, $locationAccessToken, $contactData);

            if ($contactResult) {
                Log::info('Contact upserted successfully', [
                    'contact_id' => $contactResult['contact']['id'] ?? 'unknown',
                    'email' => $contactData['email']
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Contact and custom fields processed successfully',
                    'data' => [
                        'contact' => $contactResult,
                        'custom_fields_created' => count($createdCustomFields),
                        'location_id' => $locationId
                    ]
                ], 200);
            } else {
                Log::error('Failed to upsert contact', ['contact_data' => $contactData]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to upsert contact'
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Exception during contact upsert', [
                'error' => $e->getMessage(),
                'contact_email' => $contactData['email']
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process contact: ' . $e->getMessage()
            ], 500);
        }

        // Temporary success response for verification
        // return response()->json([
        //     'status' => 'success',
        //     'message' => 'Data prepared for verification - check logs',
        //     'data' => [
        //         'total_custom_fields_prepared' => count($contactData['customFields']),
        //         'newly_created_fields' => count($createdCustomFields),
        //         'location_id' => $locationId
        //     ]
        // ], 200);




        // ----------upsert contact
        // $contactData = [
        //     'firstName' => $payload['first_name'] ?? ($payload['Rep First name'] ?? ''),
        //     'lastName' => $payload['last_name'] ?? ($payload['Rep Last name'] ?? ''),
        //     'email' => $payload['email'] ?? ($payload['Business Email'] ?? ($payload['Representative Email'] ?? '')),
        //     'phone' => $payload['phone'] ?? ($payload['Business Phone Number'] ?? ($payload['Representative Phone Number'] ?? '')),
        //     'address1' => $payload['address1'] ?? ($payload['Business Address'] ?? ''),
        //     'city' => $payload['Business City'] ?? '',
        //     'state' => $payload['Business State'] ?? '',
        //     'country' => $payload['country'] ?? ($payload['Business Country'] ?? ''),
        //     'postalCode' => $payload['Business Postal Code'] ?? '',
        //     'timezone' => $payload['timezone'] ?? '',
        //     'companyName' => $payload['Gym Name'] ?? ($payload['Legal Business Name '] ?? ''),
        //     'website' => $payload['Business website'] ?? '',
        //     'customFields' => []
        // ];

        // // Add custom field values to contact data
        // foreach ($createdCustomFields as $fieldName => $customField) {
        //     if (isset($customField['id']) && isset($customFieldsToCreate[$fieldName])) {
        //         $contactData['customFields'][] = [
        //             'id' => $customField['id'],
        //             'value' => $customFieldsToCreate[$fieldName]['value']
        //         ];
        //     }
        // }

        // Log::info('Contact data prepared for upsert', [
        //     'contact_email' => $contactData['email'],
        //     'custom_fields_count' => count($contactData['customFields']),
        //     'contact_data' => $contactData
        // ]);

        // try {
        //     // Upsert contact in GHL
        //     $contactResult = $ghlController->upsertContact($locationId, $locationAccessToken, $contactData);

        //     if ($contactResult) {
        //         Log::info('Contact upserted successfully', [
        //             'contact_id' => $contactResult['contact']['id'] ?? 'unknown',
        //             'email' => $contactData['email']
        //         ]);

        //         return response()->json([
        //             'status' => 'success',
        //             'message' => 'Contact and custom fields processed successfully',
        //             'data' => [
        //                 'contact' => $contactResult,
        //                 'custom_fields_created' => count($createdCustomFields),
        //                 'location_id' => $locationId
        //             ]
        //         ], 200);
        //     } else {
        //         Log::error('Failed to upsert contact', ['contact_data' => $contactData]);
        //         return response()->json([
        //             'status' => 'error',
        //             'message' => 'Failed to upsert contact'
        //         ], 500);
        //     }
        // } catch (\Exception $e) {
        //     Log::error('Exception during contact upsert', [
        //         'error' => $e->getMessage(),
        //         'contact_email' => $contactData['email']
        //     ]);
        //     return response()->json([
        //         'status' => 'error',
        //         'message' => 'Failed to process contact: ' . $e->getMessage()
        //     ], 500);
        // }



    }
}
