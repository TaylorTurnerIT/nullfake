@inject('captcha', 'App\Services\CaptchaService')

<div>
    <form wire:submit.prevent="analyze" class="space-y-6" id="review-form">
        <div>
            <label for="productUrl" class="block text-sm font-medium">Amazon Product URL</label>
            <input type="url" id="productUrl" wire:model.defer="productUrl" required
                   class="mt-1 w-full px-4 py-2 border rounded focus:outline-none focus:ring focus:border-indigo-300" />
            @error('productUrl')
                <div class="text-red-500 text-sm mt-1">{{ $message }}</div>
            @enderror
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
                onclick="showAnalysisProgress()">
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
</script>