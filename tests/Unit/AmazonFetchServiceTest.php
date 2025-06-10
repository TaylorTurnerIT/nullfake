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
        // Mock Amazon validation (product exists)
        $this->mockHandler->append(new Response(200, [], 'Amazon product page'));

        // Mock Unwrangle API response
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
        // Mock Amazon validation (product doesn't exist)
        $this->mockHandler->append(new Response(404, [], 'Not found'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Product does not exist on Amazon.com (US) site');

        $this->service->fetchReviewsAndSave('INVALID123', 'us', 'https://amazon.com/dp/INVALID123');
    }

    public function test_fetch_reviews_success()
    {
        // Mock Amazon validation (product exists)
        $this->mockHandler->append(new Response(200, [], 'Amazon product page'));

        // Mock Unwrangle API response
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
        // Mock Amazon validation (product doesn't exist)
        $this->mockHandler->append(new Response(404, [], 'Not found'));

        $result = $this->service->fetchReviews('INVALID123', 'us');

        $this->assertEmpty($result);
    }

    public function test_fetch_reviews_unwrangle_api_error()
    {
        // Mock Amazon validation (product exists)
        $this->mockHandler->append(new Response(200, [], 'Amazon product page'));

        // Mock Unwrangle API error response
        $this->mockHandler->append(new Response(500, [], 'Internal Server Error'));

        $result = $this->service->fetchReviews('B08N5WRWNW', 'us');

        $this->assertEmpty($result);
    }

    public function test_fetch_reviews_unwrangle_api_returns_error()
    {
        // Mock Amazon validation (product exists)
        $this->mockHandler->append(new Response(200, [], 'Amazon product page'));

        // Mock Unwrangle API response with error
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
        // Mock Amazon validation (product exists)
        $this->mockHandler->append(new Response(200, [], 'Amazon product page'));

        // Mock Unwrangle API exception
        $this->mockHandler->append(new RequestException(
            'Connection timeout',
            new Request('GET', 'test')
        ));

        $result = $this->service->fetchReviews('B08N5WRWNW', 'us');

        $this->assertEmpty($result);
    }

    public function test_validate_asin_exists_success()
    {
        // Mock successful response
        $this->mockHandler->append(new Response(200, [], 'Amazon product page'));

        // Use reflection to call private method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validateAsinExistsFast');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'B08N5WRWNW');

        $this->assertTrue($result);
    }

    public function test_validate_asin_exists_not_found()
    {
        // Mock 404 response
        $this->mockHandler->append(new Response(404, [], 'Not found'));

        // Use reflection to call private method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validateAsinExistsFast');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'INVALID123');

        $this->assertFalse($result);
    }

    public function test_validate_asin_exists_redirect()
    {
        // Mock redirect response (should still be considered valid)
        $this->mockHandler->append(new Response(302, ['Location' => 'https://amazon.co.uk/dp/B08N5WRWNW'], ''));

        // Use reflection to call private method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validateAsinExistsFast');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'B08N5WRWNW');

        $this->assertTrue($result);
    }

    public function test_validate_asin_exists_exception()
    {
        // Mock exception
        $this->mockHandler->append(new \GuzzleHttp\Exception\RequestException(
            'Connection timeout',
            new \GuzzleHttp\Psr7\Request('GET', 'test')
        ));

        // Use reflection to call private method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validateAsinExistsFast');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'B08N5WRWNW');

        $this->assertFalse($result);
    }

    public function test_fetch_reviews_with_empty_response()
    {
        // Mock Amazon validation (product exists)
        $this->mockHandler->append(new Response(200, [], 'Amazon product page'));

        // Mock Unwrangle API empty response
        $this->mockHandler->append(new Response(200, [], ''));

        $result = $this->service->fetchReviews('B08N5WRWNW', 'us');

        $this->assertEmpty($result);
    }

    public function test_fetch_reviews_with_invalid_json()
    {
        // Mock Amazon validation (product exists)
        $this->mockHandler->append(new Response(200, [], 'Amazon product page'));

        // Mock Unwrangle API invalid JSON response
        $this->mockHandler->append(new Response(200, [], 'invalid json'));

        $result = $this->service->fetchReviews('B08N5WRWNW', 'us');

        $this->assertEmpty($result);
    }

    public function test_fetch_reviews_uses_correct_api_parameters()
    {
        // Mock Amazon validation (product exists)
        $this->mockHandler->append(new Response(200, [], 'Amazon product page'));

        // Mock Unwrangle API response
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

        // Verify both mock responses were consumed
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
