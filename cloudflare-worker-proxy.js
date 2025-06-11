export default {
  async fetch(request, env, ctx) {
    // Only allow requests from your domain
    const allowedOrigins = ['https://yourdomain.com', 'https://nullfake.com'];
    const origin = request.headers.get('Origin');
    
    if (!allowedOrigins.includes(origin)) {
      return new Response('Forbidden', { status: 403 });
    }

    // Extract ASIN from request
    const url = new URL(request.url);
    const asin = url.searchParams.get('asin');
    
    if (!asin || !/^[A-Z0-9]{10}$/.test(asin)) {
      return new Response('Invalid ASIN', { status: 400 });
    }

    // Add random delay to avoid patterns
    const delay = Math.floor(Math.random() * 3000) + 1000; // 1-4 seconds
    await new Promise(resolve => setTimeout(resolve, delay));

    // Rotate user agents
    const userAgents = [
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
      'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
      'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15'
    ];
    
    const userAgent = userAgents[Math.floor(Math.random() * userAgents.length)];
    
    try {
      // Make request to Amazon
      const amazonUrl = `https://www.amazon.com/dp/${asin}`;
      const response = await fetch(amazonUrl, {
        method: 'HEAD',
        headers: {
          'User-Agent': userAgent,
          'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
          'Accept-Language': 'en-US,en;q=0.5',
          'Accept-Encoding': 'gzip, deflate',
          'DNT': '1',
          'Connection': 'keep-alive',
        },
      });

      // Return just the status code
      return new Response(JSON.stringify({
        asin: asin,
        status_code: response.status,
        timestamp: new Date().toISOString()
      }), {
        headers: {
          'Content-Type': 'application/json',
          'Access-Control-Allow-Origin': origin,
          'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
          'Access-Control-Allow-Headers': 'Content-Type',
        }
      });

    } catch (error) {
      return new Response(JSON.stringify({
        asin: asin,
        error: error.message,
        timestamp: new Date().toISOString()
      }), {
        status: 500,
        headers: {
          'Content-Type': 'application/json',
          'Access-Control-Allow-Origin': origin,
        }
      });
    }
  }
} 