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
        $this->model = config('services.openai.model', 'gpt-4');
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

        $prompt = $this->buildPrompt($reviews);

        // Log prompt size for debugging
        $promptSize = strlen($prompt);
        LoggingService::log("Prompt size: {$promptSize} characters");

        // Only chunk if prompt is extremely large (>100k chars) or too many reviews (>100)
        if ($promptSize > 100000 || count($reviews) > 100) {
            LoggingService::log('Very large payload detected, processing in chunks to avoid API limits');

            return $this->analyzeReviewsInChunks($reviews);
        }

        try {
            // Extract the endpoint from base_url if it includes the full path
            $endpoint = $this->baseUrl;
            if (!str_ends_with($endpoint, '/chat/completions')) {
                $endpoint = rtrim($endpoint, '/').'/chat/completions';
            }

            LoggingService::log("Making OpenAI API request to: {$endpoint}");

            // Determine max_tokens based on model
            $maxTokens = $this->getMaxTokensForModel($this->model);
            LoggingService::log("Using max_tokens: {$maxTokens} for model: {$this->model}");

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'ReviewAnalyzer/1.0',
            ])->timeout(300)->connectTimeout(60)->retry(3, 2000)->post($endpoint, [
                'model'    => $this->model,
                'messages' => [
                    [
                        'role'    => 'system',
                        'content' => 'You are an expert at detecting fake reviews. Analyze each review and provide a score from 0-100 where 0 is genuine and 100 is definitely fake. Return ONLY a JSON array with objects containing "id" and "score" fields.',
                    ],
                    [
                        'role'    => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.1, // Lower for more consistent results
                'max_tokens'  => $maxTokens,
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

    private function analyzeReviewsInChunks(array $reviews): array
    {
        $chunkSize = 25; // Process 25 reviews at a time
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
                usleep(500000); // 0.5 seconds
            } catch (\Exception $e) {
                LoggingService::log('Error processing chunk '.($index + 1).': '.$e->getMessage());
                // Continue with other chunks even if one fails
            }
        }

        return ['detailed_scores' => $allDetailedScores];
    }

    private function buildPrompt($reviews): string
    {
        $prompt = "Analyze these Amazon reviews for authenticity. Score each from 0-100 (0=genuine, 100=fake).\n\n";
        $prompt .= "FAKE INDICATORS: Generic language, excessive praise without specifics, promotional tone, very short 5-star reviews, missing product details, suspicious names.\n";
        $prompt .= "GENUINE INDICATORS: Specific details, balanced pros/cons, personal context, time references, ingredient mentions, detailed complaints.\n\n";
        $prompt .= "Return JSON: [{\"id\":\"X\",\"score\":Y},...]\n\n";

        foreach ($reviews as $review) {
            $verification = isset($review['meta_data']['verified_purchase']) && $review['meta_data']['verified_purchase'] ? 'Verified' : 'Unverified';
            $vine = isset($review['meta_data']['is_vine_voice']) && $review['meta_data']['is_vine_voice'] ? 'Vine' : 'Regular';

            $prompt .= "ID:{$review['id']} {$review['rating']}/5 {$verification} {$vine}\n";
            $prompt .= "Title: {$review['review_title']}\n";
            $prompt .= "Text: {$review['review_text']}\n\n";
        }

        return $prompt;
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
