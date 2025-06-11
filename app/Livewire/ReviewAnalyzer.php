<?php

namespace App\Livewire;

use App\Services\CaptchaService;
use App\Services\LoggingService;
use App\Services\ReviewAnalysisService;
use Illuminate\Http\Request;
use Livewire\Component;

/**
 * Livewire component for Amazon product review analysis interface.
 */
class ReviewAnalyzer extends Component
{
    // Form inputs
    public $productUrl = '';
    public $g_recaptcha_response = '';
    public $h_captcha_response = '';
    public $hcaptchaKey;
    public $captcha_passed = false;

    // UI state
    public $loading = false;
    public $progressCurrent = 0;
    public $progressTotal = 0;
    public $error = '';

    // Analysis results - only for display
    public $result = null;
    public $amazon_rating = null;
    public $fake_percentage = null;
    public $faker_rating = null;
    public $grade = null;
    public $grade_color = null;
    public $grade_bg = null;
    public $grade_border = null;
    public $grade_summary = null;
    public $explanation = null;
    public $asinReview = null;

    // Progress tracking properties
    public $progressStep = 0;
    public $progressSteps = [
        1 => 'Validating product URL...',
        2 => 'Authenticating request...',
        3 => 'Checking product database...',
        4 => 'Gathering review information...',
        5 => 'Analyzing reviews with AI...',
        6 => 'Calculating final metrics...',
        7 => 'Finalizing results...',
    ];
    public $progressPercentage = 0;
    public $totalReviewsFound = 0;
    public $currentlyProcessing = '';
    public $progress = 0;
    public $isAnalyzed = false;
    public $gradeColor = '';
    public $gradeBgColor = '';

    public $analysisResult = null;
    public float $adjusted_rating = 0.00;

    protected $rules = [
        'productUrl' => 'required|url',
    ];

    public function mount()
    {
        $this->hcaptchaKey = uniqid();

        // Initialize progress variables to ensure they're available in the template
        $this->progressStep = 0;
        $this->progressPercentage = 0;
        $this->currentlyProcessing = '';
        $this->loading = false;
        $this->totalReviewsFound = 0;
    }

    public function analyze()
    {
        LoggingService::log('=== LIVEWIRE ANALYZE METHOD STARTED ===');
        LoggingService::log('Product URL value: ' . ($this->productUrl ?? 'NULL'));
        LoggingService::log('Product URL length: ' . strlen($this->productUrl ?? ''));

        try {
            // Loading state and progress are already initialized by initializeProgress()
            // Just clear previous results
            $this->result = null;
            $this->error = null;
            $this->amazon_rating = null;
            $this->faker_rating = null;
            $this->fake_percentage = null;
            $this->grade = null;
            $this->grade_color = null;
            $this->grade_bg = null;
            $this->grade_border = null;
            $this->grade_summary = null;
            $this->explanation = null;
            $this->asinReview = null;
            $this->adjusted_rating = 0.00;
            $this->isAnalyzed = false;

            // Ensure productUrl is not empty before validation
            if (empty($this->productUrl)) {
                LoggingService::log('Product URL is empty before validation, attempting to get from input');
                // Try to get the value from the form if it exists
                $this->productUrl = request()->input('productUrl', $this->productUrl);
            }
            
            LoggingService::log('Final product URL before validation: ' . ($this->productUrl ?: 'EMPTY'));
            
            // Validate input
            $this->validate([
                'productUrl' => 'required|url',
            ]);

            // Captcha validation (if not local)
            if (!app()->environment('local')) {
                // Skip captcha validation if already passed in this session
                if (!$this->captcha_passed) {
                    $captchaService = app(CaptchaService::class);
                    $provider = $captchaService->getProvider();

                    if ($provider === 'recaptcha' && !empty($this->g_recaptcha_response)) {
                        if (!$captchaService->verify($this->g_recaptcha_response)) {
                            throw new \Exception('Captcha verification failed. Please try again.');
                        }
                        // Mark captcha as passed for this session
                        $this->captcha_passed = true;
                    } elseif ($provider === 'hcaptcha' && !empty($this->h_captcha_response)) {
                        if (!$captchaService->verify($this->h_captcha_response)) {
                            throw new \Exception('Captcha verification failed. Please try again.');
                        }
                        // Mark captcha as passed for this session
                        $this->captcha_passed = true;
                    } else {
                        throw new \Exception('Captcha verification failed. Please try again.');
                    }
                }
            }

            $analysisService = app(ReviewAnalysisService::class);
            $productInfo = $analysisService->checkProductExists($this->productUrl);

            $asinData = $productInfo['asin_data'];

            // Gather reviews if needed
            if ($productInfo['needs_fetching']) {
                $asinData = $analysisService->fetchReviews(
                    $productInfo['asin'],
                    $productInfo['country'],
                    $productInfo['product_url']
                );
            }

            // Analyze with OpenAI if needed
            if ($productInfo['needs_openai']) {
                $asinData = $analysisService->analyzeWithOpenAI($asinData);
            }

            // Calculate final metrics and set results
            $analysisResult = $analysisService->calculateFinalMetrics($asinData);
            $this->setResults($analysisResult);

            // Set final state
            $this->isAnalyzed = true;

            LoggingService::log('=== LIVEWIRE ANALYZE METHOD COMPLETED SUCCESSFULLY ===');
        } catch (\Exception $e) {
            LoggingService::log('Exception in analyze method: '.$e->getMessage());
            $this->error = LoggingService::handleException($e);
            $this->resetAnalysisState();
        }
    }

    private function resetAnalysisState()
    {
        // Reset progress state
        $this->loading = false;
        $this->progressStep = 0;
        $this->progressPercentage = 0;
        $this->totalReviewsFound = 0;
        $this->currentlyProcessing = '';
        $this->progress = 0;
        $this->isAnalyzed = false;
    }

    private function setResults($analysisResult)
    {
        LoggingService::log('Setting results with data: '.json_encode($analysisResult));

        $this->result = $analysisResult;
        $this->fake_percentage = $analysisResult['fake_percentage'] ?? 0;
        $this->amazon_rating = $analysisResult['amazon_rating'] ?? 0;
        $this->adjusted_rating = (float) $analysisResult['adjusted_rating'];
        $this->grade = $analysisResult['grade'] ?? 'N/A';
        $this->explanation = $analysisResult['explanation'] ?? null;

        LoggingService::log('Results set - amazon_rating: '.$this->amazon_rating.', fake_percentage: '.$this->fake_percentage.', adjusted_rating: '.$this->adjusted_rating);
        LoggingService::log('Adjusted rating type: '.gettype($this->adjusted_rating).', value: '.var_export($this->adjusted_rating, true));
    }

    public function render()
    {
        return view('livewire.review-analyzer');
    }

    public function getGradeColor()
    {
        return match ($this->grade) {
            'A'     => 'text-green-600',
            'B'     => 'text-yellow-600',
            'C'     => 'text-orange-600',
            'D'     => 'text-red-600',
            'F'     => 'text-red-800',
            default => 'text-gray-600'
        };
    }

    public function getGradeBgColor()
    {
        return match ($this->grade) {
            'A'     => 'bg-green-100',
            'B'     => 'bg-yellow-100',
            'C'     => 'bg-orange-100',
            'D'     => 'bg-red-100',
            'F'     => 'bg-red-200',
            default => 'bg-gray-100'
        };
    }

    public function clearPreviousResults()
    {
        // Clear all previous results immediately when button is clicked
        // This runs before the analyze() method
        $this->result = null;
        $this->error = null;
        $this->amazon_rating = null;
        $this->faker_rating = null;
        $this->fake_percentage = null;
        $this->grade = null;
        $this->grade_color = null;
        $this->grade_bg = null;
        $this->grade_border = null;
        $this->grade_summary = null;
        $this->explanation = null;
        $this->asinReview = null;
        $this->adjusted_rating = 0.00;
        $this->isAnalyzed = false;

        // Also reset progress state for clean start
        $this->loading = false;
        $this->progressStep = 0;
        $this->progressPercentage = 0;
        $this->currentlyProcessing = '';
        $this->progress = 0;

        // Force Livewire to re-render immediately
        $this->dispatch('resultsCleared');
    }

    public function initializeProgress()
    {
        // Initialize progress state immediately when button is clicked
        // This runs before the analyze() method
        $this->loading = true;
        $this->progressStep = 0;
        $this->progressPercentage = 0;
        $this->totalReviewsFound = 0;
        $this->currentlyProcessing = 'Starting analysis...';
        $this->progress = 0;
        $this->isAnalyzed = false;

        LoggingService::log('=== INITIALIZE PROGRESS CALLED ===');
        LoggingService::log('Progress initialized - loading: true, step: 0');
    }

    public function startAnalysis()
    {
        LoggingService::log('=== START ANALYSIS CALLED ===');

        // Clear previous results
        $this->clearPreviousResults();

        // Force sync of input values (in case wire:model.live has timing issues)
        $this->dispatch('syncInputs');

        // Run the analysis (JavaScript will handle progress simulation)
        $this->analyze();
    }

    // Method to sync the URL from JavaScript if needed
    public function setProductUrl($url)
    {
        $this->productUrl = $url;
        LoggingService::log('Product URL set via JavaScript: ' . $url);
    }
}
