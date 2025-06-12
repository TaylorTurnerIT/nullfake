<?php

namespace Tests\Feature;

use App\Livewire\ReviewAnalyzer;
use App\Models\AsinData;
use App\Services\CaptchaService;
use App\Services\ReviewAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\TestCase;

class ReviewAnalyzerLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up test environment
        config([
            'captcha.provider'             => 'recaptcha',
            'captcha.recaptcha.site_key'   => 'test_site_key',
            'captcha.recaptcha.secret_key' => 'test_secret_key',
            'captcha.recaptcha.verify_url' => 'https://www.google.com/recaptcha/api/siteverify',
        ]);
    }

    public function test_component_mounts_correctly()
    {
        $component = Livewire::test(ReviewAnalyzer::class);

        $component->assertSet('productUrl', '')
                  ->assertSet('loading', false)
                  ->assertSet('progressStep', 0)
                  ->assertSet('progressPercentage', 0)
                  ->assertSet('error', '')
                  ->assertSet('result', null)
                  ->assertSet('isAnalyzed', false);

        // Check that hcaptchaKey is set
        $this->assertNotEmpty($component->get('hcaptchaKey'));
    }

    public function test_form_validation_requires_product_url()
    {
        // Ensure we're in testing environment (should bypass captcha)
        App::shouldReceive('environment')
           ->with(['local', 'testing'])
           ->andReturn(true);

        $component = Livewire::test(ReviewAnalyzer::class);

        $component->set('productUrl', '')
                  ->call('analyze');

        // Should have specific validation error about required URL
        $error = $component->get('error');
        $this->assertNotEmpty($error, 'Expected validation error for empty URL');
        $this->assertStringContainsString('required', strtolower($error), 
            'Error should mention that URL is required, got: ' . $error);
        $this->assertFalse($component->get('isAnalyzed'));
        $this->assertFalse($component->get('loading'));
    }

    public function test_form_validation_requires_valid_url()
    {
        // Ensure we're in testing environment (should bypass captcha)
        App::shouldReceive('environment')
           ->with(['local', 'testing'])
           ->andReturn(true);

        $component = Livewire::test(ReviewAnalyzer::class);

        $component->set('productUrl', 'not-a-valid-url')
                  ->call('analyze');

        // Should have specific validation error about invalid URL format
        $error = $component->get('error');
        $this->assertNotEmpty($error, 'Expected validation error for invalid URL');
        $this->assertStringContainsString('valid', strtolower($error), 
            'Error should mention URL validity, got: ' . $error);
        $this->assertFalse($component->get('isAnalyzed'));
        $this->assertFalse($component->get('loading'));
    }

    public function test_successful_analysis_with_existing_data()
    {
        // Create test data
        $asinData = AsinData::create([
            'asin'            => 'B08N5WRWNW',
            'country'         => 'us',
            'product_url'     => 'https://www.amazon.com/dp/B08N5WRWNW',
            'reviews'         => json_encode([
                ['id' => 0, 'rating' => 5, 'review_title' => 'Great!', 'review_text' => 'Great product', 'author' => 'John'],
            ]),
            'openai_result'   => json_encode(['detailed_scores' => [0 => 25]]),
            'fake_percentage' => 0.0,
            'amazon_rating'   => 5.0,
            'adjusted_rating' => 5.0,
            'grade'           => 'A',
            'explanation'     => 'Test explanation',
            'status'          => 'completed',
        ]);

        // Mock services properly
        $this->mockCaptchaService();
        $this->mockReviewAnalysisService($asinData);

        // Ensure we're in testing environment
        App::shouldReceive('environment')
           ->with(['local', 'testing'])
           ->andReturn(true);

        $component = Livewire::test(ReviewAnalyzer::class);

        $component->set('productUrl', 'https://www.amazon.com/dp/B08N5WRWNW')
                  ->call('analyze');

        // Should succeed without errors
        $this->assertEmpty($component->get('error'), 
            'Analysis should succeed with existing data, but got error: ' . $component->get('error'));
        $this->assertTrue($component->get('isAnalyzed'));
        $this->assertFalse($component->get('loading'));
        $this->assertNotNull($component->get('result'));
        $this->assertEquals(5.0, $component->get('amazon_rating'));
        $this->assertEquals('A', $component->get('grade'));
    }

    public function test_analysis_with_fetching_needed()
    {
        // Mock services for fetching scenario
        $this->mockCaptchaService();
        $this->mockReviewAnalysisServiceForFetching();

        // Ensure we're in testing environment
        App::shouldReceive('environment')
           ->with(['local', 'testing'])
           ->andReturn(true);

        $component = Livewire::test(ReviewAnalyzer::class);

        $component->set('productUrl', 'https://www.amazon.com/dp/B08N5WRWNW')
                  ->call('analyze');

        // Should handle the fetching scenario gracefully
        $error = $component->get('error');
        if (!empty($error)) {
            // If there's an error, it should be a service-related error, not captcha
            $this->assertStringNotContainsString('Captcha', $error);
            $this->assertStringNotContainsString('captcha', strtolower($error));
        }
        $this->assertFalse($component->get('loading'));
    }

    public function test_analysis_handles_exceptions()
    {
        // Mock the ReviewAnalysisService to throw a specific exception
        $mockAnalysisService = $this->createMock(ReviewAnalysisService::class);
        $mockAnalysisService->method('checkProductExists')
                           ->willThrowException(new \Exception('Product not found on Amazon'));

        App::instance(ReviewAnalysisService::class, $mockAnalysisService);

        // Mock captcha service
        $this->mockCaptchaService();

        // Set environment to testing to skip CAPTCHA
        App::shouldReceive('environment')
           ->with(['local', 'testing'])
           ->andReturn(true);

        $component = Livewire::test(ReviewAnalyzer::class);

        $component->set('productUrl', 'https://www.amazon.com/dp/INVALID')
                  ->call('analyze')
                  ->assertSet('isAnalyzed', false)
                  ->assertSet('loading', false)
                  ->assertSet('progressStep', 0);

        // Check that error is set and is the expected error
        $error = $component->get('error');
        $this->assertNotEmpty($error, 'Expected error to be set when service throws exception');
        $this->assertStringNotContainsString('Captcha', $error, 
            'Error should not be captcha-related, got: ' . $error);
    }

    public function test_captcha_validation_in_production()
    {
        // Mock CaptchaService for failed verification
        $mockCaptchaService = $this->createMock(CaptchaService::class);
        $mockCaptchaService->method('getProvider')->willReturn('recaptcha');
        $mockCaptchaService->method('verify')->willReturn(false); // Captcha fails

        App::instance(CaptchaService::class, $mockCaptchaService);

        // Set environment to production (not local or testing)
        App::shouldReceive('environment')
           ->with(['local', 'testing'])
           ->andReturn(false);

        $component = Livewire::test(ReviewAnalyzer::class);

        $component->set('productUrl', 'https://www.amazon.com/dp/B08N5WRWNW')
                  ->set('g_recaptcha_response', 'invalid_token')
                  ->call('analyze')
                  ->assertSet('isAnalyzed', false);

        // Should have specific captcha error
        $error = $component->get('error');
        $this->assertNotEmpty($error, 'Expected captcha error in production');
        $this->assertStringContainsString('Captcha', $error, 
            'Error should be captcha-related in production, got: ' . $error);
    }

    public function test_captcha_validation_success_in_production()
    {
        // Create existing analysis data
        $asinData = AsinData::create([
            'asin'        => 'B08N5WRWNW',
            'country'     => 'us',
            'product_url' => 'https://www.amazon.com/dp/B08N5WRWNW',
            'reviews'     => json_encode([
                ['id' => 0, 'rating' => 5, 'review_title' => 'Great!', 'review_text' => 'Great product', 'author' => 'John'],
            ]),
            'openai_result'   => json_encode(['detailed_scores' => [0 => 25]]),
            'fake_percentage' => 0.0,
            'amazon_rating'   => 5.0,
            'adjusted_rating' => 5.0,
            'grade'           => 'A',
            'explanation'     => 'Test explanation',
            'status'          => 'completed',
        ]);

        // Mock CaptchaService - success
        $mockCaptchaService = $this->createMock(CaptchaService::class);
        $mockCaptchaService->method('getProvider')->willReturn('recaptcha');
        $mockCaptchaService->method('verify')->willReturn(true); // Captcha succeeds

        App::instance(CaptchaService::class, $mockCaptchaService);

        // Mock ReviewAnalysisService
        $mockAnalysisService = $this->createMock(ReviewAnalysisService::class);
        $mockAnalysisService->method('checkProductExists')
                           ->willReturn([
                               'asin'           => 'B08N5WRWNW',
                               'country'        => 'us',
                               'product_url'    => 'https://www.amazon.com/dp/B08N5WRWNW',
                               'exists'         => true,
                               'asin_data'      => $asinData,
                               'needs_fetching' => false,
                               'needs_openai'   => false,
                           ]);

        $mockAnalysisService->method('calculateFinalMetrics')
                           ->willReturn([
                               'fake_percentage' => 0.0,
                               'amazon_rating'   => 5.0,
                               'adjusted_rating' => 5.0,
                               'grade'           => 'A',
                               'explanation'     => 'Test explanation',
                               'asin_review'     => $asinData,
                           ]);

        App::instance(ReviewAnalysisService::class, $mockAnalysisService);

        // Set environment to production
        App::shouldReceive('environment')
           ->with(['local', 'testing'])
           ->andReturn(false);

        $component = Livewire::test(ReviewAnalyzer::class);

        $component->set('productUrl', 'https://www.amazon.com/dp/B08N5WRWNW')
                  ->set('g_recaptcha_response', 'valid_token')
                  ->call('analyze')
                  ->assertSet('isAnalyzed', true)
                  ->assertSet('error', null);
    }

    public function test_progress_tracking_initialization()
    {
        $component = Livewire::test(ReviewAnalyzer::class);

        $component->call('initializeProgress');

        $component->assertSet('loading', true)
                  ->assertSet('progressStep', 0)
                  ->assertSet('progressPercentage', 0)
                  ->assertSet('currentlyProcessing', 'Starting analysis...');
    }

    public function test_clear_previous_results()
    {
        $component = Livewire::test(ReviewAnalyzer::class);

        // Set some results first
        $component->set('result', ['test' => 'data'])
                  ->set('fake_percentage', 50.0)
                  ->set('grade', 'D')
                  ->set('error', 'Some error')
                  ->set('isAnalyzed', true);

        // Clear results
        $component->call('clearPreviousResults');

        $component->assertSet('result', null)
                  ->assertSet('fake_percentage', null)
                  ->assertSet('grade', null)
                  ->assertSet('error', '')
                  ->assertSet('isAnalyzed', false);
    }

    public function test_grade_color_methods()
    {
        $component = Livewire::test(ReviewAnalyzer::class);

        // Test A grade
        $component->set('grade', 'A');
        $gradeColor = $component->instance()->getGradeColor();
        $gradeBgColor = $component->instance()->getGradeBgColor();
        $this->assertEquals('text-green-600', $gradeColor);
        $this->assertEquals('bg-green-100', $gradeBgColor);

        // Test F grade
        $component->set('grade', 'F');
        $gradeColor = $component->instance()->getGradeColor();
        $gradeBgColor = $component->instance()->getGradeBgColor();
        $this->assertEquals('text-red-800', $gradeColor);
        $this->assertEquals('bg-red-200', $gradeBgColor);

        // Test C grade
        $component->set('grade', 'C');
        $gradeColor = $component->instance()->getGradeColor();
        $gradeBgColor = $component->instance()->getGradeBgColor();
        $this->assertEquals('text-orange-600', $gradeColor);
        $this->assertEquals('bg-orange-100', $gradeBgColor);
    }

    public function test_start_analysis_method()
    {
        App::shouldReceive('environment')
           ->with(['local', 'testing'])
           ->andReturn(true);

        $component = Livewire::test(ReviewAnalyzer::class);

        $component->set('productUrl', 'https://www.amazon.com/dp/B08N5WRWNW')
                  ->call('startAnalysis');

        // Should have attempted analysis and handled error gracefully
        $this->assertFalse($component->get('loading'));
        $this->assertEquals(0, $component->get('progressStep'));
        $this->assertNotEmpty($component->get('error'));
    }

    public function test_component_renders_correctly()
    {
        $component = Livewire::test(ReviewAnalyzer::class);

        $component->assertSee('Amazon Product URL')
                  ->assertSee('Analyze Reviews');
    }

    public function test_hcaptcha_provider_handling()
    {
        // Configure for hCaptcha
        config([
            'captcha.provider'          => 'hcaptcha',
            'captcha.hcaptcha.site_key' => 'test_hcaptcha_key',
        ]);

        // Mock CaptchaService for hCaptcha
        $mockCaptchaService = $this->createMock(CaptchaService::class);
        $mockCaptchaService->method('getProvider')->willReturn('hcaptcha');
        $mockCaptchaService->method('getSiteKey')->willReturn('test_hcaptcha_key');
        $mockCaptchaService->method('verify')->willReturn(true);

        App::instance(CaptchaService::class, $mockCaptchaService);

        $component = Livewire::test(ReviewAnalyzer::class);

        // Component should handle hCaptcha provider
        $this->assertNotEmpty($component->get('hcaptchaKey'));
    }

    public function test_reset_analysis_state()
    {
        $component = Livewire::test(ReviewAnalyzer::class);

        // Set some state
        $component->set('loading', true)
                  ->set('progressStep', 5)
                  ->set('progressPercentage', 80)
                  ->set('isAnalyzed', true);

        // Trigger an error to test reset
        $mockAnalysisService = $this->createMock(ReviewAnalysisService::class);
        $mockAnalysisService->method('checkProductExists')
                           ->willThrowException(new \Exception('Test error'));

        App::instance(ReviewAnalysisService::class, $mockAnalysisService);

        App::shouldReceive('environment')
           ->with(['local', 'testing'])
           ->andReturn(true);

        $component->set('productUrl', 'https://www.amazon.com/dp/B08N5WRWNW')
                  ->call('analyze');

        // State should be reset after error
        $component->assertSet('loading', false)
                  ->assertSet('progressStep', 0)
                  ->assertSet('progressPercentage', 0)
                  ->assertSet('isAnalyzed', false);
    }

    private function mockCaptchaService()
    {
        $mockCaptchaService = $this->createMock(CaptchaService::class);
        $mockCaptchaService->method('getProvider')->willReturn('recaptcha');
        $mockCaptchaService->method('verify')->willReturn(true);
        App::instance(CaptchaService::class, $mockCaptchaService);
    }

    private function mockReviewAnalysisService($asinData)
    {
        $mockAnalysisService = $this->createMock(ReviewAnalysisService::class);
        $mockAnalysisService->method('checkProductExists')
                           ->willReturn([
                               'asin'           => $asinData->asin,
                               'country'        => $asinData->country,
                               'product_url'    => $asinData->product_url,
                               'exists'         => true,
                               'asin_data'      => $asinData,
                               'needs_fetching' => false,
                               'needs_openai'   => false,
                           ]);

        $mockAnalysisService->method('calculateFinalMetrics')
                           ->willReturn([
                               'fake_percentage' => $asinData->fake_percentage,
                               'amazon_rating'   => $asinData->amazon_rating,
                               'adjusted_rating' => $asinData->adjusted_rating,
                               'grade'           => $asinData->grade,
                               'explanation'     => $asinData->explanation,
                               'asin_review'     => $asinData,
                           ]);

        App::instance(ReviewAnalysisService::class, $mockAnalysisService);
    }

    private function mockReviewAnalysisServiceForFetching()
    {
        $mockAnalysisService = $this->createMock(ReviewAnalysisService::class);
        $mockAnalysisService->method('checkProductExists')
                           ->willReturn([
                               'asin'           => 'B08N5WRWNW',
                               'country'        => 'us',
                               'product_url'    => 'https://www.amazon.com/dp/B08N5WRWNW',
                               'exists'         => true,
                               'asin_data'      => null,
                               'needs_fetching' => true,
                               'needs_openai'   => true,
                           ]);

        // Mock fetchReviews to throw an exception (simulating API failure)
        $mockAnalysisService->method('fetchReviews')
                           ->willThrowException(new \Exception('API service unavailable'));

        App::instance(ReviewAnalysisService::class, $mockAnalysisService);
    }
}
