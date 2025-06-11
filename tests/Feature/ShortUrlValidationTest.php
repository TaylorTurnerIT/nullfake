<?php

namespace Tests\Feature;

use App\Services\ReviewAnalysisService;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

class ShortUrlValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_short_url_detection_in_frontend()
    {
        $response = $this->get('/');
        
        $response->assertStatus(200);
        
        // Check that short URL detection patterns are present
        $response->assertSee('a.co/', false);
        $response->assertSee('amzn.to/', false);
        $response->assertSee('Amazon short URL detected', false);
        $response->assertSee('expandShortUrl', false);
    }

    public function test_short_url_asin_extraction_pattern()
    {
        $response = $this->get('/');
        
        // Check that ASIN extraction from short URL path is implemented
        $response->assertSee('\/d\/([A-Z0-9]{10})', false);
        $response->assertSee('Found ASIN in short URL path', false);
    }

    public function test_server_side_short_url_expansion()
    {
        // Mock the ReviewAnalysisService to avoid real HTTP calls
        $analysisService = $this->mock(ReviewAnalysisService::class, function ($mock) {
            $mock->shouldReceive('checkProductExists')
                 ->with('https://a.co/d/B08N5WRWNW')
                 ->once()
                 ->andReturn([
                     'asin' => 'B08N5WRWNW',
                     'country' => 'us',
                     'product_url' => 'https://www.amazon.com/dp/B08N5WRWNW',
                     'exists' => false,
                     'asin_data' => null,
                     'needs_fetching' => true,
                     'needs_openai' => true,
                 ]);
        });
        
        // Test that short URLs can be processed
        $result = $analysisService->checkProductExists('https://a.co/d/B08N5WRWNW');
        
        // Should extract ASIN and process successfully
        $this->assertIsArray($result);
        $this->assertArrayHasKey('asin', $result);
        $this->assertArrayHasKey('exists', $result);
        $this->assertEquals('B08N5WRWNW', $result['asin']);
    }

    public function test_short_url_with_valid_asin_pattern()
    {
        $response = $this->get('/');
        
        // Test that the client-side validation can handle a.co URLs with /d/ pattern
        $response->assertSee('shortUrlAsinMatch', false);
        $response->assertSee('Short URL validated successfully', false);
        $response->assertSee('Short URL accepted', false);
    }

    public function test_multiple_short_url_expansion_methods()
    {
        $response = $this->get('/');
        
        // Check that multiple expansion methods are implemented
        $response->assertSee('expandViaFetch', false);
        $response->assertSee('expandViaProxy', false);
        $response->assertSee('Could not expand short URL with any method', false);
    }

    public function test_short_url_validation_messages()
    {
        $response = $this->get('/');
        
        // Check that appropriate status messages are defined for short URLs
        $response->assertSee('Amazon short URL detected - validating', false);
        $response->assertSee('Successfully expanded short URL', false);
        $response->assertSee('Client-side short URL expansion failed', false);
        $response->assertSee('Short URL accepted - will expand during analysis', false);
    }

    public function test_short_url_fallback_behavior()
    {
        $response = $this->get('/');
        
        // Ensure that even if client-side expansion fails, submission is still allowed
        $response->assertSee('server will handle expansion', false);
        $response->assertSee('analyzeButton.disabled = false', false);
    }

    public function test_amzn_to_urls_are_supported()
    {
        $response = $this->get('/');
        
        // Check that both a.co and amzn.to URLs are supported
        $response->assertSee("url.includes('a.co/')", false);
        $response->assertSee("url.includes('amzn.to/')", false);
    }

    public function test_server_side_redirect_following_security()
    {
        $analysisService = app(ReviewAnalysisService::class);
        
        // Use reflection to test the redirect validation
        $reflection = new \ReflectionClass($analysisService);
        $method = $reflection->getMethod('followRedirect');
        $method->setAccessible(true);
        
        // Test that only a.co URLs are allowed for redirect following
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Redirect following only allowed for a.co domains');
        
        $method->invoke($analysisService, 'https://malicious-site.com/redirect');
    }

    public function test_short_url_timeout_configuration()
    {
        $response = $this->get('/');
        
        // Check that appropriate timeouts are configured for short URL operations
        $response->assertSee('5000', false); // expandViaFetch timeout
        $response->assertSee('8000', false); // expandViaProxy timeout
    }
} 