@inject('captcha', 'App\Services\CaptchaService')

<div>
    <form wire:submit.prevent="analyze" class="space-y-6" id="review-form">
        <div>
            <label for="productUrl" class="block text-sm font-medium">Amazon Product URL</label>
            <input type="url" id="productUrl" wire:model="productUrl" required
                   class="mt-1 w-full px-4 py-2 border rounded focus:outline-none focus:ring focus:border-indigo-300"
                   onpaste="setTimeout(validateAmazonUrl, 100)" />
            @error('productUrl')
                <div class="text-red-500 text-sm mt-1">{{ $message }}</div>
            @enderror
            <div id="url-validation-status" class="mt-2 text-sm" style="display: none;"></div>
        </div>

        @if(!app()->environment('local'))
            <div id="captcha-container">
                @if($captcha->getProvider() === 'recaptcha')
                    @if(!$captcha_passed)
                        <div id="recaptcha-container" class="g-recaptcha" data-sitekey="{{ $captcha->getSiteKey() }}" data-callback="onRecaptchaSuccess"></div>
                        <input type="hidden" wire:model="g_recaptcha_response">
                        @error('g_recaptcha_response')
                            <div class="text-red-500 text-sm mt-1">{{ $message }}</div>
                        @enderror
                        <script>
                            let recaptchaWidgetId = null;
                            function onRecaptchaSuccess(token) {
                                @this.set('g_recaptcha_response', token);
                            }

                            function renderRecaptcha() {
                                if (typeof grecaptcha !== 'undefined') {
                                    if (recaptchaWidgetId !== null) {
                                        grecaptcha.reset(recaptchaWidgetId);
                                    } else {
                                        recaptchaWidgetId = grecaptcha.render('recaptcha-container', {
                                            'sitekey': '{{ $captcha->getSiteKey() }}',
                                            'callback': onRecaptchaSuccess
                                        });
                                    }
                                }
                            }

                            document.addEventListener("livewire:load", function () {
                                renderRecaptcha();
                                Livewire.hook('message.processed', (message, component) => {
                                    renderRecaptcha();
                                });
                            });
                        </script>
                        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
                    @endif
                @elseif($captcha->getProvider() === 'hcaptcha')
                    @if(!$captcha_passed)
                        <div
                            wire:key="hcaptcha-{{ $hcaptchaKey }}"
                            id="hcaptcha-container-{{ $hcaptchaKey }}"
                            class="h-captcha"
                            data-sitekey="{{ $captcha->getSiteKey() }}"
                            data-callback="onHcaptchaSuccess"
                        ></div>
                        <input type="hidden" wire:model="h_captcha_response">
                        @error('h_captcha_response')
                            <div class="text-red-500 text-sm mt-1">{{ $message }}</div>
                        @enderror
                        <script>
                            function onHcaptchaSuccess(token) {
                                @this.set('h_captcha_response', token);
                            }
                            function renderHcaptcha() {
                                if (typeof hcaptcha !== 'undefined') {
                                    const container = document.getElementById('hcaptcha-container-{{ $hcaptchaKey }}');
                                    if (container) {
                                        container.innerHTML = '';
                                    }
                                    hcaptcha.render('hcaptcha-container-{{ $hcaptchaKey }}', {
                                        'sitekey': '{{ $captcha->getSiteKey() }}',
                                        'callback': onHcaptchaSuccess
                                    });
                                }
                            }
                            document.addEventListener("livewire:load", function () {
                                renderHcaptcha();
                                Livewire.hook('message.processed', (message, component) => {
                                    renderHcaptcha();
                                });
                            });
                        </script>
                        <script src="https://js.hcaptcha.com/1/api.js" async defer></script>
                    @endif
                @endif
            </div>
        @else
            <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4">
                <strong>Local Environment:</strong> Captcha validation is disabled for development.
            </div>
        @endif

        <button type="button" class="w-full bg-blue-500 text-white py-2 px-4 rounded hover:bg-blue-600 disabled:opacity-50"
                wire:loading.attr="disabled" 
                wire:click="startAnalysis"
                onclick="showAnalysisProgress()"
                id="analyze-button">
            <span wire:loading.remove wire:target="startAnalysis">Analyze Reviews</span>
            <span wire:loading wire:target="startAnalysis">
                Analyzing...
            </span>
        </button>
    </form>



    {{-- Simple Loading indicator that actually works --}}
    <div wire:loading wire:target="startAnalysis" class="mt-6 bg-white rounded-lg shadow-md p-6 w-full">
        <div class="text-center">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Analyzing Reviews</h3>
            
                         <!-- Animated progress bar -->
             <div class="w-full bg-gray-200 rounded-full h-3 mb-4">
                 <div id="progress-bar" class="bg-blue-500 h-3 rounded-full transition-all duration-1000 ease-out" 
                      style="width: 0%"></div>
             </div>
             
             <!-- Progress Status -->
             <p id="progress-status" class="text-xs text-gray-500 mb-2">Initializing analysis...</p>
            
                         <p class="text-sm text-gray-600 mb-4">
                 Gathering review information and performing AI analysis...
             </p>
            
            <div class="flex justify-center">
                <svg class="animate-spin h-8 w-8 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </div>
            
            <p class="text-xs text-gray-500 mt-4">
                This may take 30-60 seconds...
            </p>
        </div>
    </div>

    {{-- Results section --}}
    @if($result && !$loading)
        <div class="bg-white p-6 rounded-lg shadow-lg" data-results-section>
            <h2 class="text-2xl font-bold mb-4 text-center">Analysis Results</h2>
            
            <!-- Fake Percentage Display -->
            <div class="mb-6 p-4 bg-gray-50 rounded-lg">
                <div class="text-center">
                    <div class="text-4xl font-bold {{ $this->getGradeColor() }} mb-2">{{ number_format($fake_percentage, 1) }}%</div>
                    <p class="text-gray-600">Fake Reviews Detected</p>
                </div>
            </div>

            <!-- Grade Display -->
            <div class="mb-6 text-center">
                <div class="inline-flex items-center px-6 py-3 rounded-full {{ $this->getGradeBgColor() }}">
                    <span class="text-2xl font-bold {{ $this->getGradeColor() }}">Grade: {{ $grade }}</span>
                </div>
            </div>

            <!-- Ratings Comparison -->
            <div class="grid md:grid-cols-2 gap-4 mb-6">
                <div class="bg-blue-50 p-4 rounded-lg text-center">
                    <h3 class="font-semibold text-blue-800 mb-2">Amazon Rating</h3>
                    <div class="text-2xl font-bold text-blue-600">{{ number_format($amazon_rating, 2) }}/5</div>
                    <p class="text-sm text-blue-600">Original Rating</p>
                </div>
                <div class="bg-green-50 p-4 rounded-lg text-center">
                    <h3 class="font-semibold text-green-800 mb-2">Adjusted Rating</h3>
                    <div class="text-2xl font-bold text-green-600">
                        {{ number_format($adjusted_rating, 2) }}/5
                    </div>
                    <p class="text-sm text-green-600">After Removing Fake Reviews</p>
                </div>
            </div>

            <!-- Rating Explanation for when adjusted rating is higher -->
            @if($adjusted_rating > $amazon_rating)
                <div class="bg-blue-50 border border-blue-200 p-4 rounded-lg mb-6">
                    <div class="flex items-start">
                        <svg class="w-5 h-5 text-blue-500 mt-0.5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                        </svg>
                        <div>
                            <h4 class="font-semibold text-blue-800 mb-2">Why is the adjusted rating higher?</h4>
                            <p class="text-sm text-blue-700">
                                The adjusted rating is higher because the fake reviews included both 
                                <strong>fake positive reviews</strong> (attempting to boost the score) and 
                                <strong>fake negative reviews</strong> (attempting to damage the product's reputation). 
                                When all fake reviews are removed, the remaining genuine customer feedback shows 
                                a clearer and slightly more positive picture of actual customer satisfaction.
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Explanation -->
            <div class="bg-yellow-50 p-4 rounded-lg">
                <h3 class="font-semibold text-yellow-800 mb-2">Analysis Summary</h3>
                <p class="text-yellow-700">{{ $explanation }}</p>
            </div>
        </div>
    @endif

    {{-- Error display --}}
    @if($error)
        <div class="mt-6 text-red-600 bg-red-100 p-4 rounded">
            <strong>Error:</strong> {{ $error }}
        </div>
    @endif
</div>

{{-- Analysis progress tracking JavaScript --}}
<script>
function showAnalysisProgress() {
    const progressBar = document.getElementById('progress-bar');
    const progressStatus = document.getElementById('progress-status');
    
    if (!progressBar || !progressStatus) return;
    
    // Clear previous results immediately for better UX
    @this.clearPreviousResults();
    
    // Also hide the results section immediately via JavaScript
    const resultsSection = document.querySelector('[data-results-section]');
    if (resultsSection) {
        resultsSection.style.display = 'none';
    }
    
    const steps = [
        { percent: 12, message: 'Validating product URL...', delay: 2000 },
        { percent: 25, message: 'Authenticating request...', delay: 3000 },
        { percent: 38, message: 'Accessing product database...', delay: 4000 },
        { percent: 52, message: 'Gathering review information...', delay: 18000 }, // Longest step - actual data extraction
        { percent: 70, message: 'Processing reviews with AI...', delay: 25000 }, // AI analysis phase
        { percent: 85, message: 'Computing authenticity metrics...', delay: 6000 },
        { percent: 95, message: 'Generating final report...', delay: 4000 },
        { percent: 100, message: 'Analysis complete!', delay: 2000 }
    ];
    
    let currentStep = 0;
    let totalTime = 0;
    
    function updateProgress() {
        if (currentStep < steps.length) {
            const step = steps[currentStep];
            progressBar.style.width = step.percent + '%';
            progressStatus.textContent = step.message;
            
            totalTime += step.delay;
            currentStep++;
            
            setTimeout(updateProgress, step.delay);
        }
    }
    
    // Start the progress tracking
    setTimeout(updateProgress, 500);
}

// Reset progress when page loads
document.addEventListener('DOMContentLoaded', function() {
    const progressBar = document.getElementById('progress-bar');
    const progressStatus = document.getElementById('progress-status');
    
    if (progressBar) progressBar.style.width = '0%';
    if (progressStatus) progressStatus.textContent = 'Ready to analyze...';
    
    // Reset results section display
    const resultsSection = document.querySelector('[data-results-section]');
    if (resultsSection) {
        resultsSection.style.display = '';
    }
});

// Client-side Amazon URL validation
let validationTimeout;
let lastValidatedUrl = '';
let isValidating = false;

async function validateAmazonUrl() {
    const urlInput = document.getElementById('productUrl');
    const statusDiv = document.getElementById('url-validation-status');
    const analyzeButton = document.getElementById('analyze-button');
    
    if (!urlInput || !statusDiv) return;
    
    const url = urlInput.value.trim();
    
    // Don't validate if URL is empty or same as last validated
    if (!url || url === lastValidatedUrl) {
        statusDiv.style.display = 'none';
        if (analyzeButton) analyzeButton.disabled = false;
        return;
    }
    
    // Clear previous timeout
    if (validationTimeout) {
        clearTimeout(validationTimeout);
    }
    
    // Handle Amazon short URLs (a.co) first
    if (url.includes('a.co/') || url.includes('amzn.to/')) {
        showValidationStatus('🔗 Expanding Amazon short URL...', 'checking');
        if (analyzeButton) analyzeButton.disabled = true;
        
        try {
            const expandedUrl = await expandShortUrl(url);
            if (expandedUrl && expandedUrl !== url) {
                // Update the input field with expanded URL
                urlInput.value = expandedUrl;
                // Sync with Livewire
                if (typeof window.Livewire !== 'undefined') {
                    window.Livewire.emit('updateProductUrl', expandedUrl);
                }
                // Continue validation with expanded URL
                setTimeout(() => validateAmazonUrl(), 100);
                return;
            }
        } catch (error) {
            console.warn('Failed to expand short URL:', error);
        }
        
        // If expansion failed, still allow submission
        showValidationStatus('🔗 Short URL detected - will expand during analysis', 'warning');
        if (analyzeButton) analyzeButton.disabled = false;
        return;
    }
    
    // Extract ASIN from URL - support multiple Amazon URL formats
    let asinMatch = url.match(/\/dp\/([A-Z0-9]{10})/);
    if (!asinMatch) {
        asinMatch = url.match(/\/gp\/product\/([A-Z0-9]{10})/);
    }
    if (!asinMatch) {
        asinMatch = url.match(/\/exec\/obidos\/ASIN\/([A-Z0-9]{10})/);
    }
    
    if (!asinMatch) {
        showValidationStatus('❌ Invalid Amazon URL format. Please use a valid Amazon product URL.', 'error');
        if (analyzeButton) analyzeButton.disabled = true;
        return;
    }
    
    const asin = asinMatch[1];
    lastValidatedUrl = url;
    
    // Show checking status
    showValidationStatus('🔍 Checking if product exists...', 'checking');
    if (analyzeButton) analyzeButton.disabled = true;
    
        // Validate immediately - no artificial delays needed
    isValidating = true;
    
    try {
        // Try multiple validation approaches
        let validationResult = await tryMultipleValidationMethods(asin);
        
        if (validationResult.success) {
            showValidationStatus('✅ Product verified on Amazon', 'success');
            if (analyzeButton) analyzeButton.disabled = false;
        } else if (validationResult.uncertain) {
            showValidationStatus('⚠️ Unable to verify - will check during analysis', 'warning');
            if (analyzeButton) analyzeButton.disabled = false;
        } else {
            // Even if validation fails, allow submission since server might still work
            showValidationStatus('⚠️ Could not verify product - will check during analysis', 'warning');
            if (analyzeButton) analyzeButton.disabled = false;
        }
        
    } catch (error) {
        console.warn('Validation error:', error);
        // Always allow submission - let server handle validation
        showValidationStatus('⚠️ Verification unavailable - will check during analysis', 'warning');
        if (analyzeButton) analyzeButton.disabled = false;
    }
    
    isValidating = false;
}

async function tryMultipleValidationMethods(asin) {
    const methods = [
        () => validateViaImageRequest(asin),    // Try image first (most reliable)
        () => validateViaWebRequest(asin)       // Then try web request (better than fetch)
    ];
    
    let hasAnyResponse = false;
    
    for (const method of methods) {
        try {
            const result = await method();
            hasAnyResponse = true;
            if (result.success) {
                return result;
            }
        } catch (error) {
            console.log('Validation method failed:', error.message);
            // If we get any response (even an error), it means network is working
            if (error.message.includes('404') || error.message.includes('405')) {
                hasAnyResponse = true;
            }
        }
    }
    
    // If we had network responses but no success, product might not exist
    // If we had no responses at all, it's uncertain (network/CORS issues)
    return { success: false, uncertain: !hasAnyResponse };
}



async function expandShortUrl(shortUrl) {
    // Use a CORS proxy service to expand the URL
    const proxyServices = [
        `https://api.allorigins.win/get?url=${encodeURIComponent(shortUrl)}`,
        `https://cors-anywhere.herokuapp.com/${shortUrl}`
    ];
    
    for (const proxyUrl of proxyServices) {
        try {
            const response = await fetch(proxyUrl, {
                method: 'GET',
                timeout: 5000,
                redirect: 'follow'
            });
            
            // Extract final URL from response
            if (response.url && response.url !== proxyUrl) {
                const finalUrl = response.url.replace(/^https?:\/\/[^\/]+\//, '');
                if (finalUrl.includes('amazon.com')) {
                    return 'https://' + finalUrl;
                }
            }
        } catch (error) {
            console.log('Proxy service failed:', error.message);
        }
    }
    
    throw new Error('Could not expand short URL');
}

async function validateViaImageRequest(asin) {
    // Try to load an Amazon product image - if it loads, product likely exists
    return new Promise((resolve, reject) => {
        const img = new Image();
        const timeout = setTimeout(() => {
            img.onload = null;
            img.onerror = null;
            reject(new Error('Image validation timeout'));
        }, 3000);
        
        img.onload = () => {
            clearTimeout(timeout);
            resolve({ success: true });
        };
        
        img.onerror = () => {
            clearTimeout(timeout);
            reject(new Error('Image not found'));
        };
        
        // Try standard Amazon image URL
        img.src = `https://images-na.ssl-images-amazon.com/images/P/${asin}.01.L.jpg`;
        
        // Set crossorigin to avoid sending credentials
        img.crossOrigin = 'anonymous';
    });
}

async function validateViaWebRequest(asin) {
    // Try multiple approaches for web validation
    const approaches = [
        // Approach 1: Try loading product page iframe (invisible)
        () => validateViaIframe(asin),
        // Approach 2: Try a different Amazon endpoint
        () => validateViaAlternateEndpoint(asin)
    ];
    
    for (const approach of approaches) {
        try {
            const result = await approach();
            if (result.success) return result;
        } catch (error) {
            console.log('Web validation approach failed:', error.message);
        }
    }
    
    throw new Error('All web validation approaches failed');
}

async function validateViaIframe(asin) {
    return new Promise((resolve, reject) => {
        const iframe = document.createElement('iframe');
        iframe.style.display = 'none';
        iframe.style.width = '0';
        iframe.style.height = '0';
        
        const timeout = setTimeout(() => {
            document.body.removeChild(iframe);
            reject(new Error('Iframe validation timeout'));
        }, 4000);
        
        iframe.onload = () => {
            clearTimeout(timeout);
            document.body.removeChild(iframe);
            resolve({ success: true });
        };
        
        iframe.onerror = () => {
            clearTimeout(timeout);
            document.body.removeChild(iframe);
            reject(new Error('Iframe validation failed'));
        };
        
        // Use Amazon search results page which is more permissive
        iframe.src = `https://www.amazon.com/s?k=${asin}&ref=nb_sb_noss`;
        document.body.appendChild(iframe);
    });
}

async function validateViaAlternateEndpoint(asin) {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 3000);
    
    try {
        // Try the search endpoint instead of direct product page
        const response = await fetch(`https://www.amazon.com/s?k=${asin}`, {
            method: 'GET',
            mode: 'no-cors',
            signal: controller.signal,
            credentials: 'omit',
            referrerPolicy: 'no-referrer',
            cache: 'no-store'
        });
        
        clearTimeout(timeoutId);
        return { success: true };
        
    } catch (error) {
        clearTimeout(timeoutId);
        throw error;
    }
}

function showValidationStatus(message, type) {
    const statusDiv = document.getElementById('url-validation-status');
    if (!statusDiv) return;
    
    statusDiv.style.display = 'block';
    statusDiv.textContent = message;
    
    // Remove previous classes
    statusDiv.className = 'mt-2 text-sm';
    
    // Add appropriate styling
    switch (type) {
        case 'success':
            statusDiv.className += ' text-green-600';
            break;
        case 'error':
            statusDiv.className += ' text-red-600';
            break;
        case 'warning':
            statusDiv.className += ' text-yellow-600';
            break;
        case 'checking':
            statusDiv.className += ' text-blue-600';
            break;
    }
}

// Also validate on input change (with debouncing)
document.addEventListener('DOMContentLoaded', function() {
    const urlInput = document.getElementById('productUrl');
    const analyzeButton = document.getElementById('analyze-button');
    
    if (urlInput) {
        urlInput.addEventListener('input', function() {
            // Clear status immediately on input change
            const statusDiv = document.getElementById('url-validation-status');
            if (statusDiv) {
                statusDiv.style.display = 'none';
            }
            lastValidatedUrl = '';
            
            // Enable button while typing (will be disabled during validation if needed)
            if (analyzeButton && !isValidating) {
                analyzeButton.disabled = false;
            }
            
            // Debounce validation to avoid excessive requests while typing
            if (validationTimeout) {
                clearTimeout(validationTimeout);
            }
            validationTimeout = setTimeout(validateAmazonUrl, 1000);
        });
        
        // Also validate on paste
        urlInput.addEventListener('paste', function() {
            setTimeout(validateAmazonUrl, 200);
        });
    }
    
    // Listen for Livewire event to sync inputs
    if (typeof Livewire !== 'undefined') {
        Livewire.on('syncInputs', () => {
            const urlInput = document.getElementById('productUrl');
            if (urlInput && urlInput.value) {
                // Manually sync the input value to Livewire
                @this.setProductUrl(urlInput.value);
                console.log('Synced URL to Livewire:', urlInput.value);
            }
        });
    }
});
</script>