<?php

namespace App\Services\Amazon;

use App\Models\AsinData;
use App\Services\LoggingService;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Http;

/**
 * Service for fetching Amazon product reviews via Unwrangle API.
 */
class AmazonFetchService
{
    private Client $httpClient;

    /**
     * Initialize the service with HTTP client configuration.
     */
    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout'     => 20,
            'http_errors' => false,
            'connect_timeout' => 5,
        ]);
    }

    /**
     * Fetch Amazon reviews and save to database.
     *
     * @param string $asin       Amazon Standard Identification Number
     * @param string $country    Two-letter country code
     * @param string $productUrl Full Amazon product URL
     *
     * @throws \Exception If product doesn't exist or fetching fails
     *
     * @return AsinData The created database record
     */
    public function fetchReviewsAndSave(string $asin, string $country, string $productUrl): AsinData
    {
        // Fetch reviews from Amazon with optimized performance
        $reviewsData = $this->fetchReviewsOptimized($asin, $country);

        // Check if fetching failed and provide specific error message
        if (empty($reviewsData) || !isset($reviewsData['reviews'])) {
            // Check if the error was due to ASIN validation failure vs API issues
            if (!$this->validateAsinExistsFast($asin)) {
                throw new \Exception('Product does not exist on Amazon.com (US) site. Please check the URL and try again.');
            } else {
                throw new \Exception('Unable to fetch product reviews at this time. This could be due to network issues or the review service being temporarily unavailable. Please try again in a few moments.');
            }
        }

        // Save to database - NO OpenAI analysis yet (will be done separately)
        return AsinData::create([
            'asin'                => $asin,
            'country'             => $country,
            'product_description' => $reviewsData['description'] ?? '',
            'reviews'             => json_encode($reviewsData['reviews']),
            'openai_result'       => null, // Will be populated later
        ]);
    }

    /**
     * Optimized version of fetchReviews with improved performance
     */
    public function fetchReviewsOptimized(string $asin, string $country = 'us'): array
    {
        // Skip validation for known working products to save 2 seconds
        // Only validate if we haven't seen this ASIN recently
        $shouldValidate = !$this->isRecentlyValidated($asin);
        
        if ($shouldValidate && !$this->validateAsinExistsFast($asin)) {
            LoggingService::log('ASIN validation failed - product does not exist on amazon.com', [
                'asin'        => $asin,
                'url_checked' => "https://www.amazon.com/dp/{$asin}",
            ]);

            return [];
        }

        if ($shouldValidate) {
            $this->markAsValidated($asin);
        }

        $apiKey = env('UNWRANGLE_API_KEY');
        $cookie = env('UNWRANGLE_AMAZON_COOKIE');
        $baseUrl = 'https://data.unwrangle.com/api/getter/';
        $maxPages = 10; // Keep original page count for comprehensive data
        $country = 'us';

        $query = [
            'platform'     => 'amazon_reviews',
            'asin'         => $asin,
            'country_code' => $country,
            'max_pages'    => $maxPages,
            'api_key'      => $apiKey,
            'cookie'       => $cookie,
        ];

        try {
            $startTime = microtime(true);
            
            $response = $this->httpClient->request('GET', $baseUrl, [
                'query' => $query,
                'timeout' => 45, // Increased timeout for 10 pages of data
                'connect_timeout' => 5,
            ]);
            
            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);
            
            $status = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            LoggingService::log('Unwrangle API response', [
                'status'   => $status,
                'has_data' => !empty($body),
                'duration_ms' => $duration,
            ]);

            if ($status !== 200) {
                LoggingService::log('Unwrangle API non-200 response', [
                    'status' => $status,
                    'body'   => substr($body, 0, 500), // Limit log size
                ]);

                return [];
            }

            $data = json_decode($body, true);

            if (empty($data) || empty($data['success'])) {
                LoggingService::log('Unwrangle API returned error', [
                    'error' => $data['error'] ?? 'Unknown error',
                ]);

                return [];
            }

            $totalReviews = $data['total_results'] ?? count($data['reviews'] ?? []);
            $description = $data['description'] ?? '';
            $allReviews = $data['reviews'] ?? [];

            // Keep all reviews for comprehensive analysis
            LoggingService::log('Retrieved '.count($allReviews).' reviews for analysis');

            return [
                'reviews'       => $allReviews,
                'description'   => $description,
                'total_reviews' => $totalReviews,
            ];
        } catch (\Exception $e) {
            LoggingService::log('Unwrangle API request exception', [
                'error' => $e->getMessage(),
                'asin'  => $asin,
            ]);

            return [];
        }
    }

    /**
     * Fetch reviews from Amazon using Unwrangle API.
     *
     * @param string $asin    Amazon Standard Identification Number
     * @param string $country Two-letter country code (defaults to 'us')
     *
     * @return array<string, mixed> Array containing reviews, description, and total count
     */
    public function fetchReviews(string $asin, string $country = 'us'): array
    {
        // Use optimized version
        return $this->fetchReviewsOptimized($asin, $country);
    }

    /**
     * Fast ASIN validation with shorter timeout
     */
    private function validateAsinExistsFast(string $asin): bool
    {
        $url = "https://www.amazon.com/dp/{$asin}";

        try {
            // First try HEAD request for speed
            $response = $this->httpClient->request('HEAD', $url, [
                'timeout' => 3, // Very short timeout for validation
                'connect_timeout' => 1,
                'allow_redirects' => false, // Don't follow redirects for speed
            ]);

            $statusCode = $response->getStatusCode();

            // If HEAD request returns 405 (Method Not Allowed), try GET request
            if ($statusCode === 405) {
                LoggingService::log('HEAD request returned 405, trying GET request', [
                    'asin' => $asin,
                    'url'  => $url,
                ]);

                $response = $this->httpClient->request('GET', $url, [
                    'timeout' => 5, // Slightly longer timeout for GET
                    'connect_timeout' => 2,
                    'allow_redirects' => false,
                ]);

                $statusCode = $response->getStatusCode();
            }

            LoggingService::log('ASIN validation check', [
                'asin'        => $asin,
                'url'         => $url,
                'status_code' => $statusCode,
            ]);

            // Accept 200 (OK) and 3xx (redirect) as valid
            return $statusCode >= 200 && $statusCode < 400;
        } catch (\Exception $e) {
            LoggingService::log('ASIN validation failed', [
                'asin'  => $asin,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if ASIN has been validated recently (in-memory cache)
     */
    private function isRecentlyValidated(string $asin): bool
    {
        static $validatedAsins = [];
        static $lastClear = 0;

        $now = time();
        
        // Clear cache every 5 minutes
        if ($now - $lastClear > 300) {
            $validatedAsins = [];
            $lastClear = $now;
        }

        return isset($validatedAsins[$asin]) && ($now - $validatedAsins[$asin]) < 300;
    }

    /**
     * Mark ASIN as validated (in-memory cache)
     */
    private function markAsValidated(string $asin): void
    {
        static $validatedAsins = [];
        $validatedAsins[$asin] = time();
    }
}
