<?php

namespace App\Services;

use App\Models\Transcript;
use Exception;
use Gemini;
use Gemini\Client;
use Gemini\Data\Blob;
use Gemini\Data\GenerationConfig;
use Gemini\Data\Schema;
use Gemini\Data\ThinkingConfig;
use Gemini\Enums\DataType;
use Gemini\Enums\MimeType;
use Gemini\Enums\ResponseMimeType;
use Gemini\Exceptions\ErrorException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GeminiService
{
    protected Client $client;
    protected string $model;
    protected int $timeout;

    public function __construct()
    {
        $this->model = Config::get('services.gemini.model', 'gemini-2.5-flash');
        $this->timeout = Config::get('services.gemini.timeout', 120);
        $this->initializeClient();
    }

    /**
     * Initialize the Gemini client with proper configuration.
     */
    protected function initializeClient(): void
    {
        $apiKey = config('services.gemini.api_key');
        if (!$apiKey || $apiKey === 'your-api-key-here') {
            throw new \Exception('Gemini API key is not configured or still using placeholder value. Please set GEMINI_API_KEY in your .env file.');
        }

        $this->client = Gemini::factory()
            ->withApiKey($apiKey)
            ->withHttpClient(new \GuzzleHttp\Client(['timeout' => $this->timeout]))
            ->make();
    }

    /**
     * Transcribe a handwritten medical document from the given transcript.
     *
     * @param Transcript $transcript The transcript containing the image to process
     * @return array The structured transcript data
     * @throws Exception If transcription fails
     */
    public function transcribeMedicalDocument(Transcript $transcript): array
    {
        $startTime = microtime(true);

        Log::channel('transcription')->info('Starting Gemini API transcription', [
            'transcript_id' => $transcript->id,
            'model' => $this->model,
        ]);

        try {
            // Prepare the image
            $imageData = $this->prepareImageForApi($transcript);

            // Create the system prompt for medical transcription
            $systemPrompt = $this->createMedicalTranscriptionPrompt();

            // Generate the response with structured output
            $response = $this->client
                ->generativeModel(model: $this->model)
                ->withGenerationConfig($this->createGenerationConfig(
                    new GenerationConfig(
                        thinkingConfig: new ThinkingConfig(
                            thinkingBudget: -1,
                            includeThoughts: false
                        )
                    )
                ))
                ->generateContent([
                    $systemPrompt,
                    $imageData
                ]);

            // Parse and validate the response
            $transcriptData = $this->parseTranscriptionResponse($response);

            $processingTime = microtime(true) - $startTime;

            Log::channel('transcription')->info('Gemini API transcription completed successfully', [
                'transcript_id' => $transcript->id,
                'processing_time_seconds' => round($processingTime, 2),
                'response_size_bytes' => strlen(json_encode($transcriptData)),
            ]);

            return $transcriptData;

        } catch (ErrorException $e) {
            $this->handleGeminiApiError($e, $transcript->id);
            throw $e;
        } catch (ConnectException $e) {
            $this->handleNetworkError($e, $transcript->id);
            throw new Exception('Network connection failed. Please check your internet connection and try again.');
        } catch (RequestException $e) {
            $this->handleRequestError($e, $transcript->id);
            throw $e;
        } catch (Exception $e) {
            $this->handleGenericError($e, $transcript->id);
            throw $e;
        }
    }

    /**
     * Prepare the image data for the Gemini API.
     */
    protected function prepareImageForApi(Transcript $transcript): Blob
    {
        // Get the image data from storage - use public disk since images are stored there
        $imagePath = $transcript->image;

        if (!Storage::disk('public')->exists($imagePath)) {
            throw new Exception("Image file not found: {$imagePath}");
        }

        $imageData = Storage::disk('public')->get($imagePath);
        $mimeType = $this->detectMimeType($imagePath, $imageData);

        // Optimize image if needed (basic implementation)
        $optimizedImageData = $this->optimizeImageForApi($imageData, $mimeType);

        return new Blob(
            mimeType: $mimeType,
            data: base64_encode($optimizedImageData)
        );
    }

    /**
     * Detect the MIME type of the image.
     */
    protected function detectMimeType(string $imagePath, string $imageData): MimeType
    {
        $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMimeType = finfo_buffer($finfo, $imageData);
        finfo_close($finfo);

        return match (true) {
            $detectedMimeType === 'image/jpeg' || $extension === 'jpg' || $extension === 'jpeg' => MimeType::IMAGE_JPEG,
            $detectedMimeType === 'image/png' || $extension === 'png' => MimeType::IMAGE_PNG,
            $detectedMimeType === 'image/webp' || $extension === 'webp' => MimeType::IMAGE_WEBP,
            $detectedMimeType === 'image/heif' || $extension === 'heif' => MimeType::IMAGE_HEIF,
            $detectedMimeType === 'image/heic' || $extension === 'heic' => MimeType::IMAGE_HEIC,
            default => throw new Exception("Unsupported image format: {$detectedMimeType}")
        };
    }

    /**
     * Basic image optimization for API consumption.
     */
    protected function optimizeImageForApi(string $imageData, MimeType $mimeType): string
    {
        // For now, return the image data as-is
        // In a production environment, you might want to:
        // - Resize very large images
        // - Compress images if they're too large
        // - Convert to optimal format

        return $imageData;
    }

    /**
     * Create the system prompt for medical document transcription.
     */
    protected function createMedicalTranscriptionPrompt(): string
    {
        return <<<'PROMPT'
You are a highly skilled medical transcription specialist. Your task is to accurately transcribe handwritten medical documents, prescriptions, and clinical notes into structured, digital format.

IMPORTANT INSTRUCTIONS:
1. Transcribe ALL visible text exactly as written, preserving medical terminology and abbreviations
2. If text is unclear or illegible, note it as "illegible" rather than guessing
3. Extract and organize information into the specified JSON structure
4. Pay special attention to drug names, dosages, frequencies, and durations
5. Preserve all diagnostic information, test results, and clinical observations
6. Include doctor's name and signature information if visible
7. Use standard medical abbreviations and terminology when appropriate

QUALITY REQUIREMENTS:
- Accuracy is paramount - medical information must be precise
- If any field cannot be determined from the document, use null or appropriate default
- Ensure all required fields are populated with available information
- Maintain consistency in medical terminology and formatting

Please transcribe the handwritten medical document in the image and structure the information according to the provided JSON schema.
PROMPT;
    }

    /**
     * Create the generation configuration with structured output schema.
     */
    protected function createGenerationConfig(): GenerationConfig
    {
        return new GenerationConfig(
            responseMimeType: ResponseMimeType::APPLICATION_JSON,
            responseSchema: $this->createMedicalTranscriptSchema(),
            temperature: 0.1, // Low temperature for consistent, accurate transcription
            topP: 0.8,
            topK: 40,
            maxOutputTokens: 4000
        );
    }

    /**
     * Create the medical transcript JSON schema for structured output.
     */
    protected function createMedicalTranscriptSchema(): Schema
    {
        return new Schema(
            type: DataType::OBJECT,
            properties: [
                'patient' => new Schema(
                    type: DataType::OBJECT,
                    properties: [
                        'name' => new Schema(type: DataType::STRING),
                        'age' => new Schema(type: DataType::INTEGER),
                        'gender' => new Schema(type: DataType::STRING),
                    ],
                    required: ['name', 'age', 'gender']
                ),
                'date' => new Schema(
                    type: DataType::STRING
                ),
                'prescriptions' => new Schema(
                    type: DataType::ARRAY,
                    items: new Schema(
                        type: DataType::OBJECT,
                        properties: [
                            'drug_name' => new Schema(type: DataType::STRING),
                            'dosage' => new Schema(type: DataType::STRING),
                            'route' => new Schema(type: DataType::STRING),
                            'frequency' => new Schema(type: DataType::STRING),
                            'duration' => new Schema(type: DataType::STRING),
                            'notes' => new Schema(type: DataType::STRING, nullable: true),
                        ],
                        required: ['drug_name', 'dosage', 'route', 'frequency', 'duration']
                    )
                ),
                'diagnoses' => new Schema(
                    type: DataType::ARRAY,
                    items: new Schema(
                        type: DataType::OBJECT,
                        properties: [
                            'condition' => new Schema(type: DataType::STRING),
                            'notes' => new Schema(type: DataType::STRING, nullable: true),
                        ],
                        required: ['condition']
                    )
                ),
                'observations' => new Schema(
                    type: DataType::ARRAY,
                    items: new Schema(type: DataType::STRING)
                ),
                'tests' => new Schema(
                    type: DataType::ARRAY,
                    items: new Schema(
                        type: DataType::OBJECT,
                        properties: [
                            'test_name' => new Schema(type: DataType::STRING),
                            'result' => new Schema(type: DataType::STRING, nullable: true),
                            'normal_range' => new Schema(type: DataType::STRING, nullable: true),
                            'notes' => new Schema(type: DataType::STRING, nullable: true),
                        ],
                        required: ['test_name']
                    )
                ),
                'instructions' => new Schema(type: DataType::STRING),
                'doctor' => new Schema(
                    type: DataType::OBJECT,
                    properties: [
                        'name' => new Schema(type: DataType::STRING),
                        'signature' => new Schema(type: DataType::STRING),
                    ],
                    required: ['name', 'signature']
                ),
            ],
            required: ['patient', 'date', 'prescriptions', 'diagnoses', 'observations', 'tests', 'instructions', 'doctor']
        );
    }

    /**
     * Parse and validate the transcription response.
     */
    protected function parseTranscriptionResponse($response): array
    {
        try {
            $jsonData = $response->json();

            if (empty($jsonData)) {
                throw new Exception('Empty response from Gemini API');
            }

            // Convert stdClass to array if needed
            if (is_object($jsonData)) {
                $jsonData = json_decode(json_encode($jsonData), true);
            }

            // Basic validation of required fields
            $requiredFields = ['patient', 'date', 'prescriptions', 'diagnoses', 'observations', 'tests', 'instructions', 'doctor'];
            foreach ($requiredFields as $field) {
                if (!isset($jsonData[$field])) {
                    throw new Exception("Missing required field: {$field}");
                }
            }

            return $jsonData;

        } catch (Exception $e) {
            Log::channel('transcription')->error('Failed to parse Gemini API response', [
                'error' => $e->getMessage(),
                'response_text' => $response->text() ?? 'No response text',
            ]);
            throw new Exception('Failed to parse transcription response: ' . $e->getMessage());
        }
    }

    /**
     * Handle Gemini API specific errors.
     */
    protected function handleGeminiApiError(ErrorException $e, int $transcriptId): void
    {
        $errorDetails = [
            'transcript_id' => $transcriptId,
            'error_code' => $e->getCode(),
            'error_message' => $e->getMessage(),
        ];

        Log::channel('transcription')->error('Gemini API error', $errorDetails);
    }

    /**
     * Handle network connection errors.
     */
    protected function handleNetworkError(ConnectException $e, int $transcriptId): void
    {
        Log::channel('transcription')->error('Network connection error', [
            'transcript_id' => $transcriptId,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Handle HTTP request errors.
     */
    protected function handleRequestError(RequestException $e, int $transcriptId): void
    {
        $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 'unknown';

        Log::channel('transcription')->error('HTTP request error', [
            'transcript_id' => $transcriptId,
            'status_code' => $statusCode,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Handle generic errors.
     */
    protected function handleGenericError(Exception $e, int $transcriptId): void
    {
        Log::channel('transcription')->error('Generic transcription error', [
            'transcript_id' => $transcriptId,
            'error_class' => get_class($e),
            'error_message' => $e->getMessage(),
            'error_trace' => $e->getTraceAsString(),
        ]);
    }

    /**
     * Get user-friendly error message based on the exception type.
     */
    public function getUserFriendlyErrorMessage(Exception $exception): string
    {
        $message = $exception->getMessage();

        // Handle specific Gemini API errors
        if ($exception instanceof ErrorException) {
            return match (true) {
                str_contains($message, 'quota') || str_contains($message, 'limit') => 'The transcription service is temporarily at capacity. Please try again in a few minutes.',
                str_contains($message, 'invalid') && str_contains($message, 'image') => 'The uploaded image format is not supported or is corrupted. Please try uploading a different image.',
                str_contains($message, 'safety') => 'The document content could not be processed due to safety restrictions. Please contact support if you believe this is an error.',
                str_contains($message, 'authentication') || str_contains($message, 'unauthorized') => 'There was an authentication issue with the transcription service. Please contact support.',
                default => 'The transcription service encountered an error. Please try again later.',
            };
        }

        // Handle network errors
        if ($exception instanceof ConnectException) {
            return 'Unable to connect to the transcription service. Please check your internet connection and try again.';
        }

        // Handle file-related errors
        if (str_contains($message, 'Image file not found') || str_contains($message, 'file')) {
            return 'There was a problem reading your image file. Please try uploading the image again.';
        }

        // Handle parsing errors
        if (str_contains($message, 'parse') || str_contains($message, 'json')) {
            return 'The transcription result could not be processed. Please try again.';
        }

        // Handle configuration errors
        if (str_contains($message, 'API key not configured') || str_contains($message, 'configuration')) {
            return 'The transcription service is not properly configured. Please contact support.';
        }

        // Generic fallback
        return 'An unexpected error occurred during transcription. Our team has been notified. Please try again later.';
    }
}
