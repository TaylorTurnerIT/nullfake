<?php

namespace App\Services\Amazon;

use App\Models\AsinData;
use App\Exceptions\ReviewServiceException;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Services\LoggingService;

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
            'timeout' => 30,
            'http_errors' => false,
        ]);
    }

    /**
     * Fetch Amazon reviews and save to database.
     *
     * @param string $asin Amazon Standard Identification Number
     * @param string $country Two-letter country code
     * @param string $productUrl Full Amazon product URL
     * @return AsinData The created database record
     * @throws \Exception If product doesn't exist or fetching fails
     */
    public function fetchReviewsAndSave(string $asin, string $country, string $productUrl): AsinData
    {
        // Fetch reviews from Amazon
        $reviewsData = $this->fetchReviews($asin, $country);
        
        // Check if fetching failed (empty reviews data means validation failed)
        if (empty($reviewsData) || !isset($reviewsData['reviews'])) {
            throw new \Exception('Product does not exist on Amazon.com (US) site. Please check the URL and try again.');
        }
        
        // Save to database - NO OpenAI analysis yet (will be done separately)
        return AsinData::create([
            'asin' => $asin,
            'country' => $country,
            'product_description' => $reviewsData['description'] ?? '',
            'reviews' => json_encode($reviewsData['reviews']),
            'openai_result' => null, // Will be populated later
        ]);
    }

    /**
     * Fetch reviews from Amazon using Unwrangle API.
     *
     * @param string $asin Amazon Standard Identification Number
     * @param string $country Two-letter country code (defaults to 'us')
     * @return array<string, mixed> Array containing reviews, description, and total count
     */
    public function fetchReviews(string $asin, string $country = 'us'): array
    {
        // Check if the ASIN exists on Amazon US before calling Unwrangle API
        if (!$this->validateAsinExists($asin)) {
            LoggingService::log('ASIN validation failed - product does not exist on amazon.com', [
                'asin' => $asin,
                'url_checked' => "https://www.amazon.com/dp/{$asin}"
            ]);
            return [];
        }

        $apiKey = env('UNWRANGLE_API_KEY');
        $cookie = env('UNWRANGLE_AMAZON_COOKIE');
        $baseUrl = 'https://data.unwrangle.com/api/getter/';
        $maxPages = 10;
        $country = 'us'; // Always use US country for session cookie match
    
        $query = [
            'platform' => 'amazon_reviews',
            'asin' => $asin,
            'country_code' => $country,
            'max_pages' => $maxPages,
            'api_key' => $apiKey,
            'cookie' => $cookie,
        ];

        try {
            $response = $this->httpClient->request('GET', $baseUrl, ['query' => $query]);
            $status = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            LoggingService::log('Unwrangle API response', [
                'status' => $status,
                'has_data' => !empty($body)
            ]);

            if ($status !== 200) {
                LoggingService::log('Unwrangle API non-200 response', [
                    'status' => $status,
                    'body' => $body
                ]);
                return [];
            }

            $data = json_decode($body, true);

            if (empty($data) || empty($data['success'])) {
                LoggingService::log('Unwrangle API returned error', [
                    'error' => $data['error'] ?? 'Unknown error'
                ]);
                return [];
            }

            $totalReviews = $data['total_results'] ?? count($data['reviews'] ?? []);
            $description = $data['description'] ?? '';
            $allReviews = $data['reviews'] ?? [];

            return [
                'reviews' => $allReviews,
                'description' => $description,
                'total_reviews' => $totalReviews,
            ];

        } catch (\Exception $e) {
            LoggingService::log('Unwrangle API request exception', [
                'error' => $e->getMessage(),
                'asin' => $asin
            ]);
            return [];
        }
    }

    /**
     * Validate that an ASIN exists on Amazon US by checking the product page.
     *
     * @param string $asin Amazon Standard Identification Number
     * @return bool True if product exists (returns 200), false otherwise
     */
    private function validateAsinExists(string $asin): bool
    {
        $url = "https://www.amazon.com/dp/{$asin}";
        
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 10,
                'allow_redirects' => false, // Don't follow redirects to catch geo-redirects
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'Cache-Control' => 'no-cache',
                    'Pragma' => 'no-cache',
                    'Upgrade-Insecure-Requests' => '1',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'none',
                    'Sec-Fetch-User' => '?1'
                ],
                'http_errors' => false // Don't throw exceptions on 4xx/5xx
            ]);
            
            $statusCode = $response->getStatusCode();
            
            LoggingService::log('ASIN validation check', [
                'asin' => $asin,
                'url' => $url,
                'status_code' => $statusCode
            ]);
            
            return $statusCode === 200;
            
        } catch (\Exception $e) {
            LoggingService::log('ASIN validation failed with exception', [
                'asin' => $asin,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }


}
