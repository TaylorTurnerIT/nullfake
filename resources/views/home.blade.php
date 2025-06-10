<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Null Fake - Amazon Review Analysis</title>
  <meta name="description" content="Null Fake is an AI-powered tool that analyzes Amazon product reviews for authenticity. Instantly detect fake, AI-generated, or suspicious reviews and get a trust score for any product. Built for shoppers and sellers who want to make informed decisions." />
  <meta name="keywords" content="Amazon review analysis, fake review detector, AI review analysis, Amazon authenticity checker, product review trust, Amazon Canada, Amazon US, review analyzer, AI fake review, e-commerce trust, product authenticity, review scoring" />
  <meta name="author" content="shift8 web" />
  
  <!-- SEO and Robots Configuration -->
  <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1" />
  <meta name="googlebot" content="index, follow" />
  <meta name="bingbot" content="index, follow" />
  
  <link rel="canonical" href="https://nullfake.com/" />
  <link rel="sitemap" type="application/xml" href="/sitemap.xml" />

  <!-- Favicon and App Icons -->
  <link rel="apple-touch-icon" sizes="57x57" href="/img/apple-icon-57x57.png">
  <link rel="apple-touch-icon" sizes="60x60" href="/img/apple-icon-60x60.png">
  <link rel="apple-touch-icon" sizes="72x72" href="/img/apple-icon-72x72.png">
  <link rel="apple-touch-icon" sizes="76x76" href="/img/apple-icon-76x76.png">
  <link rel="apple-touch-icon" sizes="114x114" href="/img/apple-icon-114x114.png">
  <link rel="apple-touch-icon" sizes="120x120" href="/img/apple-icon-120x120.png">
  <link rel="apple-touch-icon" sizes="144x144" href="/img/apple-icon-144x144.png">
  <link rel="apple-touch-icon" sizes="152x152" href="/img/apple-icon-152x152.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/img/apple-icon-180x180.png">
  <link rel="icon" type="image/png" sizes="192x192"  href="/img/android-icon-192x192.png">
  <link rel="icon" type="image/png" sizes="32x32" href="/img/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="96x96" href="/img/favicon-96x96.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/img/favicon-16x16.png">
  <link rel="manifest" href="/manifest.json">
  <meta name="msapplication-TileColor" content="#ffffff">
  <meta name="msapplication-TileImage" content="/img/ms-icon-144x144.png">
  <meta name="theme-color" content="#ffffff">

  <!-- Open Graph / Facebook -->
  <meta property="og:type" content="website" />
  <meta property="og:url" content="https://nullfake.com/" />
  <meta property="og:title" content="Null Fake - Amazon Review Analysis" />
  <meta property="og:description" content="AI-powered Amazon review analyzer. Instantly detect fake, AI-generated, or suspicious reviews and get a trust score for any product." />
  <meta property="og:image" content="/img/nullfake.png" />

  <!-- Twitter -->
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:url" content="https://nullfake.com/" />
  <meta name="twitter:title" content="Null Fake - Amazon Review Analysis" />
  <meta name="twitter:description" content="AI-powered Amazon review analyzer. Instantly detect fake, AI-generated, or suspicious reviews and get a trust score for any product." />
  <meta name="twitter:image" content="/img/nullfake.png" />

  <!-- Schema.org for Google -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "WebApplication",
    "name": "Null Fake - Amazon Review Analysis",
    "url": "https://nullfake.com/",
    "description": "Null Fake is an AI-powered tool that analyzes Amazon product reviews for authenticity. Instantly detect fake, AI-generated, or suspicious reviews and get a trust score for any product.",
    "applicationCategory": "ProductivityApplication",
    "operatingSystem": "All",
    "offers": {
      "@type": "Offer",
      "price": "0",
      "priceCurrency": "USD"
    },
    "image": "https://nullfake.com/img/nullfake.png",
    "author": {
      "@type": "Organization",
      "name": "shift8 web",
      "url": "https://shift8web.ca"
    }
  }
  </script>

  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  @livewireStyles

  <!-- Google tag (gtag.js) -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-BYWNNLXEYV"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());

    gtag('config', 'G-BYWNNLXEYV');
  </script>

</head>
<body class="bg-gray-100 text-gray-800">

  <header class="bg-white shadow p-4">
    <div class="max-w-4xl mx-auto flex items-center justify-between">
      <div class="flex items-center space-x-3">
        <img src="/img/nullfake.png" alt="Null Fake Logo" class="h-12 w-auto object-contain" />
      </div>
      <p class="text-sm text-gray-500">Analyze Amazon reviews for authenticity</p>
    </div>
  </header>

  <main class="max-w-3xl mx-auto mt-10 p-6 bg-white rounded shadow">
  <div class="pb-4 text-sm">AI-powered review analyzer. Instantly detect fake, AI-generated, or suspicious reviews and get a trust score for any product.</div>

    {{-- Livewire Review Analyzer replaces the form --}}
    <livewire:review-analyzer />
  </main>

  <footer class="text-center text-gray-500 text-sm mt-10 p-2">
    built with
    <span class="inline-block align-middle text-red-500" aria-label="love" title="love" style="position:relative; top:-4px;">
      <svg xmlns="http://www.w3.org/2000/svg" class="inline align-middle h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
        <path d="M3.172 5.172a4 4 0 015.656 0L10 6.343l1.172-1.171a4 4 0 115.656 5.656L10 17.657l-6.828-6.829a4 4 0 010-5.656z"/>
      </svg>
    </span>
    by <a href="https://shift8web.ca" class="text-indigo-600 hover:underline" target="_blank" rel="noopener">shift8 web</a>
  </footer>

  @livewireScripts
</body>
</html>
