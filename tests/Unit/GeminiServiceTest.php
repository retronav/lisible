<?php

use App\Models\Transcript;
use App\Services\GeminiService;
use Exception;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Config::set('services.gemini', [
        'api_key' => 'test_api_key_12345',
        'timeout' => 300,
        'model' => 'gemini-2.0-flash',
    ]);

    // Create a test transcript to ensure DB is ready for any dependent operations
    Transcript::factory()->create([
        'title' => 'Test Medical Document',
        'description' => 'A test prescription document',
        'image' => 'test-images/sample-prescription.jpg',
        'status' => 'pending',
    ]);

    Storage::fake('local');
    Storage::put('test-images/sample-prescription.jpg', 'fake-image-content');
});

it('service can be instantiated with valid api key', function () {
    $service = new GeminiService();
    expect($service)->toBeInstanceOf(GeminiService::class);
});

it('service throws exception with missing api key', function () {
    Config::set('services.gemini.api_key', null);

    $this->expectException(Exception::class);
    $this->expectExceptionMessage('Gemini API key is not configured');

    new GeminiService();
});

it('service throws exception with placeholder api key', function () {
    Config::set('services.gemini.api_key', 'your-api-key-here');

    $this->expectException(Exception::class);
    $this->expectExceptionMessage('still using placeholder value');

    new GeminiService();
});

it('get user friendly error message handles gemini api errors', function () {
    $service = new GeminiService();

    $genericError = new Exception('Some error occurred');
    $genericMessage = $service->getUserFriendlyErrorMessage($genericError);
    expect($genericMessage)->toContain('unexpected error occurred');

    expect(true)->toBeTrue();
});

it('get user friendly error message handles network errors', function () {
    $service = new GeminiService();

    $networkError = new ConnectException('Connection timeout', new \GuzzleHttp\Psr7\Request('GET', 'test'));
    $message = $service->getUserFriendlyErrorMessage($networkError);
    expect($message)->toContain('check your internet connection');
});

it('get user friendly error message handles file errors', function () {
    $service = new GeminiService();

    $fileError = new Exception('Image file not found: test.jpg');
    $message = $service->getUserFriendlyErrorMessage($fileError);
    expect($message)->toContain('try uploading the image again');
});

it('get user friendly error message handles parsing errors', function () {
    $service = new GeminiService();

    $parseError = new Exception('Failed to parse JSON response');
    $message = $service->getUserFriendlyErrorMessage($parseError);
    expect($message)->toContain('transcription result could not be processed');
});

it('get user friendly error message handles configuration errors', function () {
    $service = new GeminiService();

    $configError = new Exception('API key not configured properly');
    $message = $service->getUserFriendlyErrorMessage($configError);
    expect($message)->toContain('not properly configured');
});

it('get user friendly error message provides generic fallback', function () {
    $service = new GeminiService();

    $genericError = new Exception('Unknown error occurred');
    $message = $service->getUserFriendlyErrorMessage($genericError);
    expect($message)->toContain('unexpected error occurred');
    expect($message)->toContain('try again later');
});

it('medical transcript schema structure', function () {
    $service = new GeminiService();
    $reflection = new \ReflectionClass($service);
    $method = $reflection->getMethod('createMedicalTranscriptSchema');
    $method->setAccessible(true);

    $schema = $method->invoke($service);

    expect($schema)->toBeInstanceOf(\Gemini\Data\Schema::class);
    expect($schema->type)->toBe(\Gemini\Enums\DataType::OBJECT);

    $expectedProperties = [
        'patient', 'date', 'prescriptions', 'diagnoses',
        'observations', 'tests', 'instructions', 'doctor'
    ];
    foreach ($expectedProperties as $property) {
        expect($schema->properties)->toHaveKey($property);
    }
    expect($schema->required)->toBe($expectedProperties);
});

it('medical transcription prompt contains key instructions', function () {
    $service = new GeminiService();
    $reflection = new \ReflectionClass($service);
    $method = $reflection->getMethod('createMedicalTranscriptionPrompt');
    $method->setAccessible(true);

    $prompt = $method->invoke($service);

    expect($prompt)->toContain('medical transcription specialist');
    expect($prompt)->toContain('handwritten medical documents');
    expect($prompt)->toContain('drug names, dosages, frequencies');
    expect($prompt)->toContain('Accuracy is paramount');
    expect($prompt)->toContain('JSON schema');
});

it('image mime type detection', function () {
    $service = new GeminiService();
    $reflection = new \ReflectionClass($service);
    $method = $reflection->getMethod('detectMimeType');
    $method->setAccessible(true);

    $testCases = [
        ['image.jpg', 'image/jpeg', \Gemini\Enums\MimeType::IMAGE_JPEG],
        ['image.jpeg', 'image/jpeg', \Gemini\Enums\MimeType::IMAGE_JPEG],
        ['image.png', 'image/png', \Gemini\Enums\MimeType::IMAGE_PNG],
        ['image.webp', 'image/webp', \Gemini\Enums\MimeType::IMAGE_WEBP],
    ];

    foreach ($testCases as [$filename, $mimeType, $expectedEnum]) {
        $fakeImageData = 'fake-image-data';
        if (!function_exists('finfo_open')) {
            $this->markTestSkipped('finfo extension not available');
        }
        $result = $method->invoke($service, $filename, $fakeImageData);
        expect($result)->toBe($expectedEnum);
    }
});

it('image mime type detection throws exception for unsupported format', function () {
    $service = new GeminiService();
    $reflection = new \ReflectionClass($service);
    $method = $reflection->getMethod('detectMimeType');
    $method->setAccessible(true);

    $this->expectException(Exception::class);
    $this->expectExceptionMessage('Unsupported image format');

    $method->invoke($service, 'image.gif', 'fake-image-data');
});
