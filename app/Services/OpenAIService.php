<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?? '';
        $this->model = config('services.openai.model', 'gpt-4o-mini');
        $this->baseUrl = config('services.openai.base_url', 'https://api.openai.com/v1');

        if (empty($this->apiKey)) {
            throw new \InvalidArgumentException('OpenAI API key is not configured. Please set OPENAI_API_KEY in your .env file.');
        }
    }

    public function analyzeReviews(array $reviews): array
    {
        if (empty($reviews)) {
            return ['results' => []];
        }

        // Log the number of reviews being sent
        LoggingService::log('Sending '.count($reviews).' reviews to OpenAI for analysis');

        // For better performance, process in parallel chunks if we have many reviews
        $parallelThreshold = config('services.openai.parallel_threshold', 50);
        if (count($reviews) > $parallelThreshold) {
            LoggingService::log('Large dataset detected ('.count($reviews).' reviews), processing in parallel chunks');
            return $this->analyzeReviewsInParallelChunks($reviews);
        }

        $prompt = $this->buildOptimizedPrompt($reviews);

        // Log prompt size for debugging
        $promptSize = strlen($prompt);
        LoggingService::log("Optimized prompt size: {$promptSize} characters");

        try {
            // Extract the endpoint from base_url if it includes the full path
            $endpoint = $this->baseUrl;
            if (!str_ends_with($endpoint, '/chat/completions')) {
                $endpoint = rtrim($endpoint, '/').'/chat/completions';
            }

            LoggingService::log("Making OpenAI API request to: {$endpoint}");

            // Use optimized parameters for faster processing
            $maxTokens = $this->getOptimizedMaxTokens(count($reviews));
            $timeout = config('services.openai.timeout', 120);
            LoggingService::log("Using optimized max_tokens: {$maxTokens} for {$this->model} with ".count($reviews)." reviews, timeout: {$timeout}s");

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'ReviewAnalyzer/1.0',
            ])->timeout($timeout)->connectTimeout(30)->retry(2, 1000)->post($endpoint, [
                'model'    => $this->model,
                'messages' => [
                    [
                        'role'    => 'system',
                        'content' => 'You are a fast review authenticity detector. Analyze each review and return ONLY a JSON array with {"id":"X","score":Y} objects. Score 0-100 where 0=genuine, 100=fake.',
                    ],
                    [
                        'role'    => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.0, // Deterministic for consistency and speed
                'max_tokens'  => $maxTokens,
                'top_p' => 0.1, // More focused responses
            ]);

            if ($response->successful()) {
                LoggingService::log('OpenAI API request successful');
                $result = $response->json();

                return $this->parseOpenAIResponse($result, $reviews);
            } else {
                Log::error('OpenAI API error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                throw new \Exception('OpenAI API request failed: '.$response->status());
            }
        } catch (\Exception $e) {
            LoggingService::log('OpenAI service error', ['error' => $e->getMessage()]);

            // Check if it's a timeout with 0 bytes - this suggests connection issues
            if (str_contains($e->getMessage(), 'cURL error 28') && str_contains($e->getMessage(), '0 bytes received')) {
                throw new \Exception('Unable to connect to OpenAI service. Please check your internet connection and try again.');
            }

            throw new \Exception('Failed to analyze reviews: '.$e->getMessage());
        }
    }

    /**
     * Process reviews in parallel chunks for better performance with large datasets
     */
    private function analyzeReviewsInParallelChunks(array $reviews): array
    {
        $chunkSize = config('services.openai.chunk_size', 25);
        $chunks = array_chunk($reviews, $chunkSize);
        $allDetailedScores = [];

        LoggingService::log('Processing '.count($chunks).' chunks in parallel for '.count($reviews).' reviews');

        // Process chunks concurrently using Laravel's HTTP pool
        $responses = Http::pool(function ($pool) use ($chunks) {
            $endpoint = $this->baseUrl;
            if (!str_ends_with($endpoint, '/chat/completions')) {
                $endpoint = rtrim($endpoint, '/').'/chat/completions';
            }

            foreach ($chunks as $index => $chunk) {
                $prompt = $this->buildOptimizedPrompt($chunk);
                $maxTokens = $this->getOptimizedMaxTokens(count($chunk));

                $pool->withHeaders([
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type'  => 'application/json',
                    'User-Agent'    => 'ReviewAnalyzer/1.0',
                ])->timeout(60)->connectTimeout(20)->post($endpoint, [
                    'model'    => $this->model,
                    'messages' => [
                        [
                            'role'    => 'system',
                            'content' => 'You are a fast review authenticity detector. Analyze each review and return ONLY a JSON array with {"id":"X","score":Y} objects. Score 0-100 where 0=genuine, 100=fake.',
                        ],
                        [
                            'role'    => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'temperature' => 0.0,
                    'max_tokens'  => $maxTokens,
                    'top_p' => 0.1,
                ]);
            }
        });

        // Process all responses
        foreach ($responses as $chunkIndex => $response) {
            $chunk = $chunks[$chunkIndex];

            if ($response->successful()) {
                $result = $response->json();
                $chunkResult = $this->parseOpenAIResponse($result, $chunk);

                if (isset($chunkResult['detailed_scores'])) {
                    $allDetailedScores = array_merge($allDetailedScores, $chunkResult['detailed_scores']);
                }

                LoggingService::log('Successfully processed chunk '.($chunkIndex + 1).' ('.count($chunk).' reviews)');
            } else {
                LoggingService::log('Error processing chunk '.($chunkIndex + 1).': HTTP '.$response->status());
                // Continue with other chunks even if one fails
            }
        }

        LoggingService::log('Parallel processing completed with '.count($allDetailedScores).' total scores');

        return ['detailed_scores' => $allDetailedScores];
    }

    private function analyzeReviewsInChunks(array $reviews): array
    {
        $chunkSize = config('services.openai.chunk_size', 25);
        $chunks = array_chunk($reviews, $chunkSize);
        $allDetailedScores = [];

        foreach ($chunks as $index => $chunk) {
            LoggingService::log('Processing chunk '.($index + 1).' of '.count($chunks).' ('.count($chunk).' reviews)');

            try {
                $chunkResult = $this->analyzeReviews($chunk);

                if (isset($chunkResult['detailed_scores'])) {
                    $allDetailedScores = array_merge($allDetailedScores, $chunkResult['detailed_scores']);
                }

                // Small delay between chunks to avoid rate limiting
                usleep(200000); // Reduced to 0.2 seconds
            } catch (\Exception $e) {
                LoggingService::log('Error processing chunk '.($index + 1).': '.$e->getMessage());
                // Continue with other chunks even if one fails
            }
        }

        return ['detailed_scores' => $allDetailedScores];
    }

    /**
     * Build an optimized prompt that uses fewer tokens while maintaining accuracy
     */
    private function buildOptimizedPrompt($reviews): string
    {
        $prompt = "Score each review 0-100 (0=real, 100=fake). Return JSON: [{\"id\":\"X\",\"score\":Y}]\n\n";
        $prompt .= "FAKE SIGNS: Generic text, excessive praise, no specifics, promotional tone, short 5-stars\n";
        $prompt .= "REAL SIGNS: Specific details, balanced feedback, personal context, complaints\n\n";

        foreach ($reviews as $review) {
            $verified = isset($review['meta_data']['verified_purchase']) && $review['meta_data']['verified_purchase'] ? 'V' : 'U';
            $vine = isset($review['meta_data']['is_vine_voice']) && $review['meta_data']['is_vine_voice'] ? 'Vine' : '';
            
            // Clean and truncate text to handle UTF-8 issues
            $title = $this->cleanUtf8Text(substr($review['review_title'], 0, 100));
            $text = $this->cleanUtf8Text(substr($review['review_text'], 0, 400));

            $prompt .= "ID:{$review['id']} {$review['rating']}/5 {$verified}{$vine}\n";
            $prompt .= "T: {$title}\n";
            $prompt .= "R: {$text}\n\n";
        }

        return $prompt;
    }

    /**
     * Clean text to ensure valid UTF-8 encoding for JSON serialization
     */
    private function cleanUtf8Text(string $text): string
    {
        // Remove or replace invalid UTF-8 sequences
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        
        // Remove null bytes and other problematic characters
        $text = str_replace(["\0", "\x1A"], '', $text);
        
        // Ensure string is valid UTF-8
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'auto');
        }
        
        return trim($text);
    }

    /**
     * Get optimized max_tokens based on number of reviews for faster processing
     */
    private function getOptimizedMaxTokens(int $reviewCount): int
    {
        // Calculate based on expected output size: roughly 20 chars per review for JSON response
        $baseTokens = $reviewCount * 8; // More conservative estimate
        
        // Add buffer but keep it minimal for speed
        $buffer = min(500, $reviewCount * 2);
        
        return $baseTokens + $buffer;
    }

    private function buildPrompt($reviews): string
    {
        // Fallback to old method if needed
        return $this->buildOptimizedPrompt($reviews);
    }

    private function parseOpenAIResponse($response, $reviews): array
    {
        $content = $response['choices'][0]['message']['content'] ?? '';

        LoggingService::log('Raw OpenAI response content: '.json_encode(['content' => $content]));

        // Try to extract JSON from the content
        if (preg_match('/\[[\s\S]*\]/', $content, $matches)) {
            $jsonString = $matches[0];

            if ($jsonString) {
                LoggingService::log('Extracted JSON string: '.json_encode(['json' => substr($jsonString, 0, 100).'...']));

                try {
                    $results = json_decode($jsonString, true);

                    if (json_last_error() === JSON_ERROR_NONE && is_array($results)) {
                        $detailedScores = [];
                        foreach ($results as $result) {
                            if (isset($result['id']) && isset($result['score'])) {
                                $detailedScores[$result['id']] = (int) $result['score'];
                            }
                        }

                        return [
                            'detailed_scores' => $detailedScores,
                        ];
                    }
                } catch (\Exception $e) {
                    LoggingService::log('Failed to parse OpenAI JSON response: '.$e->getMessage());
                }
            }
        }

        LoggingService::log('Failed to parse OpenAI response, using fallback');

        // Fallback: return empty detailed scores
        return [
            'detailed_scores' => [],
        ];
    }

    private function getMaxTokensForModel(string $model): int
    {
        // Map models to their max completion tokens
        $modelLimits = [
            'gpt-4'                  => 4096,
            'gpt-4-0613'             => 4096,
            'gpt-4-32k'              => 32768,
            'gpt-4-32k-0613'         => 32768,
            'gpt-4-turbo'            => 4096,
            'gpt-4-turbo-preview'    => 4096,
            'gpt-4-1106-preview'     => 4096,
            'gpt-4-0125-preview'     => 4096,
            'gpt-3.5-turbo'          => 4096,
            'gpt-3.5-turbo-16k'      => 16384,
            'gpt-3.5-turbo-0613'     => 4096,
            'gpt-3.5-turbo-16k-0613' => 16384,
        ];

        return $modelLimits[$model] ?? 4096; // Default to 4096 if model not found
    }
}
