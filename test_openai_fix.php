<?php

require_once 'vendor/autoload.php';

use App\Services\ReviewAnalysisService;

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    echo "Testing OpenAI service fix...\n";
    
    $service = app(ReviewAnalysisService::class);
    
    // Test with a product that had the issue
    $result = $service->analyzeProduct('B0C3QZ7SNF');
    
    echo "Analysis completed successfully!\n";
    echo "Fake percentage: {$result['fake_percentage']}%\n";
    echo "Amazon rating: {$result['amazon_rating']}\n";
    echo "Adjusted rating: {$result['adjusted_rating']}\n";
    echo "Grade: {$result['grade']}\n";
    
    if ($result['fake_percentage'] > 0) {
        echo "✅ SUCCESS: Fix is working - detecting fake reviews!\n";
    } else {
        echo "⚠️  WARNING: Still showing 0% fake - check logs for details\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
} 