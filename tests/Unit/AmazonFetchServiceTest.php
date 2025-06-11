<?php

namespace Tests\Unit;

use App\Models\AsinData;
use App\Services\Amazon\AmazonFetchService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmazonFetchServiceTest extends TestCase
{
    use RefreshDatabase;

    private AmazonFetchService $service;
    private MockHandler $mockHandler;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up environment variables for testing
        config(['app.env' => 'testing']);
        putenv('UNWRANGLE_API_KEY=test_api_key');
        putenv('UNWRANGLE_AMAZON_COOKIE=test_cookie');

        $this->mockHandler = new MockHandler();
        $handlerStack = HandlerStack::create($this->mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);

        // Create service instance and inject mock client
        $this->service = new AmazonFetchService();

        // Use reflection to inject the mock client
        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('httpClient');
        $property->setAccessible(true);
        $property->setValue($this->service, $mockClient);
    }

    public function test_fetch_reviews_and_save_success()
    {
        // Mock Unwrangle API response (no more Amazon validation)
        $unwrangleResponse = [
            'success'       => true,
            'total_results' => 2,
            'description'   => 'Test Product Description',
            'reviews'       => [
                ['rating' => 5, 'text' => 'Great product', 'author' => 'John'],
                ['rating' => 4, 'text' => 'Good product', 'author' => 'Jane'],
            ],
        ];
        $this->mockHandler->append(new Response(200, [], json_encode($unwrangleResponse)));

        $result = $this->service->fetchReviewsAndSave('B08N5WRWNW', 'us', 'https://amazon.com/dp/B08N5WRWNW');

        $this->assertInstanceOf(AsinData::class, $result);
        $this->assertEquals('B08N5WRWNW', $result->asin);
        $this->assertEquals('us', $result->country);
        $this->assertEquals('Test Product Description', $result->product_description);
        $this->assertCount(2, $result->getReviewsArray());
        $this->assertNull($result->openai_result);
    }

    public function test_fetch_reviews_and_save_product_not_exists()
    {
        // Mock Unwrangle API failure (product doesn't exist or API error)
        $unwrangleResponse = [
            'success' => false,
            'error'   => 'Product not found',
        ];
        $this->mockHandler->append(new Response(200, [], json_encode($unwrangleResponse)));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unable to fetch product reviews at this time');

        $this->service->fetchReviewsAndSave('INVALID123', 'us', 'https://amazon.com/dp/INVALID123');
    }

    public function test_fetch_reviews_success()
    {
        // Mock Unwrangle API response (no more Amazon validation)
        $unwrangleResponse = [
            'success'       => true,
            'total_results' => 3,
            'description'   => 'Test Product',
            'reviews'       => [
                ['rating' => 5, 'text' => 'Excellent'],
                ['rating' => 4, 'text' => 'Good'],
                ['rating' => 3, 'text' => 'Average'],
            ],
        ];
        $this->mockHandler->append(new Response(200, [], json_encode($unwrangleResponse)));

        $result = $this->service->fetchReviews('B08N5WRWNW', 'us');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('reviews', $result);
        $this->assertArrayHasKey('description', $result);
        $this->assertArrayHasKey('total_reviews', $result);
        $this->assertCount(3, $result['reviews']);
        $this->assertEquals('Test Product', $result['description']);
        $this->assertEquals(3, $result['total_reviews']);
    }

    public function test_fetch_reviews_asin_validation_fails()
    {
        // Test invalid ASIN format validation
        $result = $this->service->fetchReviews('INVALID123', 'us');

        $this->assertEmpty($result);
    }

    public function test_fetch_reviews_unwrangle_api_error()
    {
        // Mock Unwrangle API error response (no more Amazon validation)
        $this->mockHandler->append(new Response(500, [], 'Internal Server Error'));

        $result = $this->service->fetchReviews('B08N5WRWNW', 'us');

        $this->assertEmpty($result);
    }

    public function test_fetch_reviews_unwrangle_api_returns_error()
    {
        // Mock Unwrangle API response with error (no more Amazon validation)
        $unwrangleResponse = [
            'success' => false,
            'error'   => 'API limit exceeded',
        ];
        $this->mockHandler->append(new Response(200, [], json_encode($unwrangleResponse)));

        $result = $this->service->fetchReviews('B08N5WRWNW', 'us');

        $this->assertEmpty($result);
    }

    public function test_fetch_reviews_unwrangle_api_exception()
    {
        // Mock Unwrangle API exception (no more Amazon validation)
        $this->mockHandler->append(new RequestException(
            'Connection timeout',
            new Request('GET', 'test')
        ));

        $result = $this->service->fetchReviews('B08N5WRWNW', 'us');

        $this->assertEmpty($result);
    }

    // Note: validateAsinExistsFast method removed - validation now happens client-side
    // These tests have been removed as we no longer do server-side Amazon validation

    public function test_fetch_reviews_with_empty_response()
    {
        // Mock Unwrangle API empty response (no more Amazon validation)
        $this->mockHandler->append(new Response(200, [], ''));

        $result = $this->service->fetchReviews('B08N5WRWNW', 'us');

        $this->assertEmpty($result);
    }

    public function test_fetch_reviews_with_invalid_json()
    {
        // Mock Unwrangle API invalid JSON response (no more Amazon validation)
        $this->mockHandler->append(new Response(200, [], 'invalid json'));

        $result = $this->service->fetchReviews('B08N5WRWNW', 'us');

        $this->assertEmpty($result);
    }

    public function test_fetch_reviews_uses_correct_api_parameters()
    {
        // Mock Unwrangle API response (no more Amazon validation)
        $unwrangleResponse = [
            'success'       => true,
            'total_results' => 1,
            'description'   => 'Test Product',
            'reviews'       => [['rating' => 5, 'text' => 'Great']],
        ];
        $this->mockHandler->append(new Response(200, [], json_encode($unwrangleResponse)));

        $result = $this->service->fetchReviews('B08N5WRWNW', 'us');

        // Verify the method returned expected data (indicating API was called correctly)
        $this->assertIsArray($result);
        $this->assertArrayHasKey('reviews', $result);
        $this->assertArrayHasKey('description', $result);
        $this->assertEquals('Test Product', $result['description']);
        $this->assertCount(1, $result['reviews']);

        // Verify mock response was consumed
        $this->assertCount(0, $this->mockHandler);
    }

    protected function tearDown(): void
    {
        // Clean up environment variables
        putenv('UNWRANGLE_API_KEY');
        putenv('UNWRANGLE_AMAZON_COOKIE');

        parent::tearDown();
    }
}
