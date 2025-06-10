<?php

namespace App\Services;

use App\Models\AsinData;
use App\Services\Amazon\AmazonFetchService;

class ReviewAnalysisService
{
    private AmazonFetchService $fetchService;
    private ReviewService $reviewService;
    private OpenAIService $openAIService;

    public function __construct(
        AmazonFetchService $fetchService,
        ReviewService $reviewService,
        OpenAIService $openAIService
    ) {
        $this->fetchService = $fetchService;
        $this->reviewService = $reviewService;
        $this->openAIService = $openAIService;
    }

    private function extractAsinFromUrl($url): string
    {
        // Handle short URLs (a.co redirects)
        if (preg_match('/^https?:\/\/a\.co\//', $url)) {
            $url = $this->followRedirect($url);
            LoggingService::log("Followed redirect to: {$url}");
        }

        // Extract ASIN from various Amazon URL patterns
        $patterns = [
            '/\/dp\/([A-Z0-9]{10})/',           // /dp/ASIN
            '/\/product\/([A-Z0-9]{10})/',      // /product/ASIN
            '/\/product-reviews\/([A-Z0-9]{10})/', // /product-reviews/ASIN
            '/\/gp\/product\/([A-Z0-9]{10})/',  // /gp/product/ASIN
            '/ASIN=([A-Z0-9]{10})/',            // ASIN=ASIN parameter
            '/\/([A-Z0-9]{10})(?:\/|\?|$)/',    // ASIN in path
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                LoggingService::log("Extracted ASIN '{$matches[1]}' using pattern: {$pattern}");

                return $matches[1];
            }
        }

        // If it's already just an ASIN
        if (preg_match('/^[A-Z0-9]{10}$/', $url)) {
            return $url;
        }

        throw new \Exception('Could not extract ASIN from URL: '.$url);
    }

    private function followRedirect(string $url): string
    {
        // Only follow redirects for trusted domains
        if (!preg_match('/^https?:\/\/a\.co\//', $url)) {
            throw new \Exception('Redirect following only allowed for a.co domains');
        }

        try {
            LoggingService::log("Following redirect for URL: {$url}");

            // Track redirects manually using on_redirect callback
            $redirectUrls = [];
            $client = new \GuzzleHttp\Client([
                'timeout'         => 10,
                'allow_redirects' => [
                    'max'         => 5,
                    'strict'      => true,
                    'referer'     => true,
                    'on_redirect' => function ($request, $response, $uri) use (&$redirectUrls) {
                        $redirectUrls[] = (string) $uri;
                    },
                ],
                'headers' => [
                    'User-Agent'                => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                    'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language'           => 'en-US,en;q=0.5',
                    'Accept-Encoding'           => 'gzip, deflate',
                    'Connection'                => 'keep-alive',
                    'Upgrade-Insecure-Requests' => '1',
                ],
            ]);

            $response = $client->get($url);
            $finalUrl = !empty($redirectUrls) ? end($redirectUrls) : $url;

            LoggingService::log('Redirect resolved', [
                'original_url'   => $url,
                'final_url'      => $finalUrl,
                'status_code'    => $response->getStatusCode(),
                'redirect_chain' => $redirectUrls,
            ]);

            // Verify the redirect goes to Amazon
            if (preg_match('/^https?:\/\/(?:www\.)?amazon\.(com|co\.uk|ca|com\.au|de|fr|it|es|in|co\.jp|com\.mx|com\.br|nl|sg|com\.tr|ae|sa|se|pl|eg|be)/', $finalUrl)) {
                return $finalUrl;
            } else {
                throw new \Exception('Redirect does not lead to Amazon domain: '.$finalUrl);
            }
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            LoggingService::log('Redirect following failed: '.$e->getMessage());

            throw new \Exception('Failed to follow redirect: '.$e->getMessage());
        } catch (\Exception $e) {
            LoggingService::log('Redirect following failed: '.$e->getMessage());

            throw new \Exception('Failed to follow redirect: '.$e->getMessage());
        }
    }

    public function analyzeProduct(string $asin, string $country = 'us'): array
    {
        LoggingService::log('=== ANALYZE METHOD STARTED ===');

        try {
            // Handle both URL string and direct ASIN inputs
            if (is_string($asin)) {
                $asin = $this->extractAsinFromUrl($asin);
                $country = 'us'; // Default country
                $productUrl = "https://www.amazon.com/dp/{$asin}";

                // Step 1: Check if analysis already exists in database
                $asinData = AsinData::where('asin', $asin)->where('country', $country)->first();

                if (!$asinData) {
                    LoggingService::log('Product not found in database, starting fetch process for ASIN: '.$asin);
                    LoggingService::log('Progress: 1/4 - Gathering Amazon reviews...');

                    // Step 2: Fetch reviews from Amazon
                    $asinData = $this->fetchService->fetchReviewsAndSave($asin, $country, $productUrl);

                    if (!$asinData) {
                        throw new \Exception("This product (ASIN: {$asin}) does not exist on Amazon US. Please verify the product URL and ensure it's available on amazon.com.");
                    }

                    LoggingService::log('Gathered '.count($asinData->getReviewsArray()).' reviews, now sending to OpenAI for analysis');

                    // Step 4: Analyze with OpenAI and update the same record
                    $openaiResult = $this->openAIService->analyzeReviews($asinData->getReviewsArray());

                    // Step 5: Update the record with OpenAI analysis
                    $asinData->update([
                        'openai_result' => json_encode($openaiResult),
                        'status'        => 'completed',
                    ]);

                    LoggingService::log('Updated database record with OpenAI analysis results');
                } else {
                    LoggingService::log("Found existing analysis in database for ASIN: {$asin}");

                    // Check if OpenAI analysis is missing and complete it if needed
                    if (!$asinData->openai_result) {
                        LoggingService::log("OpenAI analysis missing, completing analysis for ASIN: {$asin}");

                        $reviews = $asinData->getReviewsArray();
                        if (empty($reviews)) {
                            throw new \Exception('No reviews found in database for analysis');
                        }

                        LoggingService::logProgress('Completing analysis', 'Analyzing reviews with OpenAI...');
                        $openaiResult = $this->openAIService->analyzeReviews($reviews);

                        $asinData->update([
                            'openai_result' => json_encode($openaiResult),
                            'status'        => 'completed',
                        ]);

                        LoggingService::log("Completed missing OpenAI analysis for ASIN: {$asin}");

                        // Refresh the model to get updated data
                        $asinData = $asinData->fresh();
                    }
                }
            } else {
                // Input is already an AsinData object
                $asinData = $asin;
            }

            // Step 6: Calculate final metrics from the data
            if (!$asinData->openai_result) {
                throw new \Exception('No OpenAI analysis data available for this product');
            }

            // Calculate metrics directly from the data
            $openaiResult = $asinData->openai_result;
            if (is_string($openaiResult)) {
                $openaiResult = json_decode($openaiResult, true);
            }

            $detailedScores = $openaiResult['detailed_scores'] ?? [];
            $reviews = $asinData->getReviewsArray();

            $totalReviews = count($reviews);
            $fakeCount = 0;
            $amazonRatingSum = 0;
            $genuineRatingSum = 0;
            $genuineCount = 0;

            LoggingService::log('=== STARTING CALCULATION DEBUG ===');
            LoggingService::log("Total reviews found: {$totalReviews}");
            LoggingService::log('Detailed scores count: '.count($detailedScores));

            $fakeReviews = [];
            $genuineReviews = [];

            foreach ($reviews as $review) {
                $reviewId = $review['id'];
                $rating = $review['rating'];
                $amazonRatingSum += $rating;

                $fakeScore = $detailedScores[$reviewId] ?? 0;

                if ($fakeScore >= 70) {
                    $fakeCount++;
                    $fakeReviews[] = [
                        'id'         => $reviewId,
                        'rating'     => $rating,
                        'fake_score' => $fakeScore,
                    ];
                    LoggingService::log("FAKE REVIEW: ID={$reviewId}, Rating={$rating}, Score={$fakeScore}");
                } else {
                    $genuineRatingSum += $rating;
                    $genuineCount++;
                    $genuineReviews[] = [
                        'id'         => $reviewId,
                        'rating'     => $rating,
                        'fake_score' => $fakeScore,
                    ];
                }
            }

            LoggingService::log('=== FAKE REVIEWS SUMMARY ===');
            LoggingService::log("Total fake reviews: {$fakeCount}");
            foreach ($fakeReviews as $fake) {
                LoggingService::log("Fake: {$fake['id']} - Rating: {$fake['rating']} - Score: {$fake['fake_score']}");
            }

            LoggingService::log('=== GENUINE REVIEWS SUMMARY ===');
            LoggingService::log("Total genuine reviews: {$genuineCount}");
            $genuineRatingCounts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
            foreach ($genuineReviews as $genuine) {
                $genuineRatingCounts[$genuine['rating']]++;
            }
            foreach ($genuineRatingCounts as $rating => $count) {
                LoggingService::log("Genuine {$rating}-star reviews: {$count}");
            }

            $fakePercentage = $totalReviews > 0 ? ($fakeCount / $totalReviews) * 100 : 0;
            $amazonRating = $totalReviews > 0 ? $amazonRatingSum / $totalReviews : 0;
            $adjustedRating = $this->calculateAdjustedRating($genuineReviews);

            LoggingService::log('=== FINAL CALCULATIONS ===');
            LoggingService::log("Amazon rating sum: {$amazonRatingSum}");
            LoggingService::log("Amazon rating average: {$amazonRating}");
            LoggingService::log("Genuine rating sum: {$genuineRatingSum}");
            LoggingService::log("Genuine rating average: {$adjustedRating}");
            LoggingService::log("Fake percentage: {$fakePercentage}%");

            $grade = $this->calculateGrade($fakePercentage);
            $explanation = $this->generateExplanation($totalReviews, $fakeCount, $fakePercentage);

            // Update the database with calculated values
            $asinData->update([
                'fake_percentage' => round($fakePercentage, 1),
                'amazon_rating'   => round($amazonRating, 2),
                'adjusted_rating' => round($adjustedRating, 2),
                'grade'           => $grade,
                'explanation'     => $explanation,
            ]);

            return [
                'fake_percentage' => round($fakePercentage, 1),
                'amazon_rating'   => round($amazonRating, 2),
                'adjusted_rating' => round($adjustedRating, 2),
                'grade'           => $grade,
                'explanation'     => $explanation,
                'asin_review'     => $asinData->fresh(),
            ];
        } catch (\Exception $e) {
            $userMessage = LoggingService::handleException($e);

            throw new \Exception($userMessage);
        }
    }

    private function calculateGrade($fakePercentage): string
    {
        if ($fakePercentage <= 10) {
            return 'A';
        } elseif ($fakePercentage <= 20) {
            return 'B';
        } elseif ($fakePercentage <= 35) {
            return 'C';
        } elseif ($fakePercentage <= 50) {
            return 'D';
        } else {
            return 'F';
        }
    }

    private function generateExplanation($totalReviews, $fakeCount, $fakePercentage): string
    {
        return "Analysis of {$totalReviews} reviews found {$fakeCount} potentially fake reviews (".round($fakePercentage, 1).'%). '.
               ($fakePercentage <= 10 ? 'This product has very low fake review activity and appears highly trustworthy.' :
               ($fakePercentage <= 20 ? 'This product has low fake review activity and appears trustworthy.' :
               ($fakePercentage <= 35 ? 'This product has moderate fake review activity. Exercise some caution.' :
               ($fakePercentage <= 50 ? 'This product has high fake review activity. Exercise caution when purchasing.' :
                'This product has very high fake review activity. We recommend avoiding this product.'))));
    }

    private function calculateAdjustedRating($genuineReviews)
    {
        LoggingService::log('calculateAdjustedRating called with '.count($genuineReviews).' genuine reviews');

        if (empty($genuineReviews)) {
            LoggingService::log('No genuine reviews found, returning 0');

            return 0;
        }

        $totalRating = 0;
        foreach ($genuineReviews as $review) {
            $totalRating += $review['rating'];
            LoggingService::log('Adding rating: '.$review['rating'].', total so far: '.$totalRating);
        }

        $adjustedRating = $totalRating / count($genuineReviews);
        $roundedRating = round($adjustedRating, 2);

        LoggingService::log('Final calculation: '.$totalRating.' / '.count($genuineReviews).' = '.$adjustedRating);
        LoggingService::log('Rounded result: '.$roundedRating);

        return $roundedRating;
    }

    /**
     * Phase 1: Extract ASIN and check if product exists in database.
     */
    public function checkProductExists(string $productUrl): array
    {
        $asin = $this->extractAsinFromUrl($productUrl);
        $country = 'us';
        $productUrl = "https://www.amazon.com/dp/{$asin}";

        $asinData = AsinData::where('asin', $asin)->where('country', $country)->first();

        return [
            'asin'           => $asin,
            'country'        => $country,
            'product_url'    => $productUrl,
            'exists'         => $asinData !== null,
            'asin_data'      => $asinData,
            'needs_fetching' => $asinData === null,
            'needs_openai'   => $asinData === null || !$asinData->openai_result,
        ];
    }

    /**
     * Phase 2: Fetch reviews from Amazon (if needed).
     */
    public function fetchReviews(string $asin, string $country, string $productUrl): AsinData
    {
        LoggingService::log("Starting scrape process for ASIN: {$asin}");

        $asinData = $this->fetchService->fetchReviewsAndSave($asin, $country, $productUrl);

        if (!$asinData) {
            throw new \Exception("This product (ASIN: {$asin}) does not exist on Amazon US. Please verify the product URL and ensure it's available on amazon.com.");
        }

        LoggingService::log('Gathered '.count($asinData->getReviewsArray()).' reviews');

        return $asinData;
    }

    /**
     * Phase 3: Analyze reviews with OpenAI (if needed).
     */
    public function analyzeWithOpenAI(AsinData $asinData): AsinData
    {
        $reviews = $asinData->getReviewsArray();

        if (empty($reviews)) {
            throw new \Exception('No reviews found for analysis');
        }

        LoggingService::log('Sending '.count($reviews).' reviews to OpenAI for analysis');

        $openaiResult = $this->openAIService->analyzeReviews($reviews);

        $asinData->update([
            'openai_result' => json_encode($openaiResult),
            'status'        => 'completed',
        ]);

        LoggingService::log('Updated database record with OpenAI analysis results');

        return $asinData->fresh();
    }

    /**
     * Phase 4: Calculate final metrics and grades.
     */
    public function calculateFinalMetrics(AsinData $asinData): array
    {
        if (!$asinData->openai_result) {
            throw new \Exception('No OpenAI analysis data available for this product');
        }

        // Calculate metrics directly from the data
        $openaiResult = $asinData->openai_result;
        if (is_string($openaiResult)) {
            $openaiResult = json_decode($openaiResult, true);
        }

        $detailedScores = $openaiResult['detailed_scores'] ?? [];
        $reviews = $asinData->getReviewsArray();

        $totalReviews = count($reviews);
        $fakeCount = 0;
        $amazonRatingSum = 0;
        $genuineRatingSum = 0;
        $genuineCount = 0;

        LoggingService::log('=== STARTING CALCULATION DEBUG ===');
        LoggingService::log("Total reviews found: {$totalReviews}");
        LoggingService::log('Detailed scores count: '.count($detailedScores));

        $fakeReviews = [];
        $genuineReviews = [];

        foreach ($reviews as $review) {
            $reviewId = $review['id'];
            $rating = $review['rating'];
            $amazonRatingSum += $rating;

            $fakeScore = $detailedScores[$reviewId] ?? 0;

            if ($fakeScore >= 70) {
                $fakeCount++;
                $fakeReviews[] = [
                    'id'         => $reviewId,
                    'rating'     => $rating,
                    'fake_score' => $fakeScore,
                ];
                LoggingService::log("FAKE REVIEW: ID={$reviewId}, Rating={$rating}, Score={$fakeScore}");
            } else {
                $genuineRatingSum += $rating;
                $genuineCount++;
                $genuineReviews[] = [
                    'id'         => $reviewId,
                    'rating'     => $rating,
                    'fake_score' => $fakeScore,
                ];
            }
        }

        LoggingService::log('=== FAKE REVIEWS SUMMARY ===');
        LoggingService::log("Total fake reviews: {$fakeCount}");
        foreach ($fakeReviews as $fake) {
            LoggingService::log("Fake: {$fake['id']} - Rating: {$fake['rating']} - Score: {$fake['fake_score']}");
        }

        LoggingService::log('=== GENUINE REVIEWS SUMMARY ===');
        LoggingService::log("Total genuine reviews: {$genuineCount}");
        $genuineRatingCounts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($genuineReviews as $genuine) {
            $genuineRatingCounts[$genuine['rating']]++;
        }
        foreach ($genuineRatingCounts as $rating => $count) {
            LoggingService::log("Genuine {$rating}-star reviews: {$count}");
        }

        $fakePercentage = $totalReviews > 0 ? ($fakeCount / $totalReviews) * 100 : 0;
        $amazonRating = $totalReviews > 0 ? $amazonRatingSum / $totalReviews : 0;
        $adjustedRating = $this->calculateAdjustedRating($genuineReviews);

        LoggingService::log('=== FINAL CALCULATIONS ===');
        LoggingService::log("Amazon rating sum: {$amazonRatingSum}");
        LoggingService::log("Amazon rating average: {$amazonRating}");
        LoggingService::log("Genuine rating sum: {$genuineRatingSum}");
        LoggingService::log("Genuine rating average: {$adjustedRating}");
        LoggingService::log("Fake percentage: {$fakePercentage}%");

        $grade = $this->calculateGrade($fakePercentage);
        $explanation = $this->generateExplanation($totalReviews, $fakeCount, $fakePercentage);

        // Update the database with calculated values
        $asinData->update([
            'fake_percentage' => round($fakePercentage, 1),
            'amazon_rating'   => round($amazonRating, 2),
            'adjusted_rating' => round($adjustedRating, 2),
            'grade'           => $grade,
            'explanation'     => $explanation,
        ]);

        return [
            'fake_percentage' => round($fakePercentage, 1),
            'amazon_rating'   => round($amazonRating, 2),
            'adjusted_rating' => round($adjustedRating, 2),
            'grade'           => $grade,
            'explanation'     => $explanation,
            'asin_review'     => $asinData->fresh(),
        ];
    }
}
