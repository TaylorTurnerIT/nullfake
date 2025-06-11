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
            // Since we're not doing server-side validation, assume it's an API issue
            throw new \Exception('Unable to fetch product reviews at this time. This could be due to an invalid product URL, network issues, or the review service being temporarily unavailable. Please verify the Amazon URL and try again.');
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
        // Only do basic ASIN format validation - let client-side JS and Unwrangle API handle the rest
        if (!$this->isValidAsinFormat($asin)) {
            LoggingService::log('ASIN format validation failed', [
                'asin' => $asin,
            ]);
            return [];
        }
        
        LoggingService::log('Server-side validation skipped - using client-side validation', [
            'asin' => $asin,
        ]);

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
     * Validate ASIN format without hitting Amazon servers
     */
    private function isValidAsinFormat(string $asin): bool
    {
        // ASIN should be exactly 10 characters, alphanumeric
        return preg_match('/^[A-Z0-9]{10}$/', $asin) === 1;
    }


}
