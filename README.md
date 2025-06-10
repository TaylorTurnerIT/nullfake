# Null Fake

A Laravel application that analyzes Amazon product reviews to detect fake reviews using AI. The service fetches reviews via the Unwrangle API, analyzes them with OpenAI, and provides authenticity scores.

# Visit [nullfake.com](https://nullfake.com) to try it out.

## How It Works

1. User submits an Amazon product URL and completes a captcha
2. Null Fake retrieves the ASIN and country from the URL
3. Database check: If a review analysis for the given ASIN and country exists in the database (and is less than 30 days old), the cached OpenAI analysis is returned instantly. If not, the service fetches reviews using the Unwrangle API, analyzes them with OpenAI, and saves the entire interaction to the database for future requests
4. Results are displayed to the user, including a fake review percentage, grade, explanation, and ratings

## Features

- Amazon review fetching via Unwrangle API
- Product validation before analysis
- AI analysis using OpenAI to detect fake reviews
- Database caching for fast repeat lookups
- Captcha protection (reCAPTCHA and hCaptcha support)
- Real-time progress tracking during analysis

## Database Schema

The `asin_data` table stores:
- `asin` - Amazon Standard Identification Number
- `country` - Country code (e.g., 'us', 'ca')
- `product_description` - Product description from Amazon
- `reviews` - JSON array of fetched reviews from Unwrangle API
- `openai_result` - JSON of full OpenAI analysis with detailed scores

The model calculates:
- `fake_percentage` - Percentage of reviews flagged as potentially fake (score ≥ 70)
- `grade` - Letter grade (A-F) based on fake review percentage
- `explanation` - Human-readable analysis summary
- `amazon_rating` - Original average rating from all reviews
- `adjusted_rating` - Adjusted rating excluding fake reviews

## Technology Stack

- Laravel 12 with Livewire 3
- Unwrangle API for Amazon review data
- OpenAI GPT-4 for review authenticity analysis
- MySQL/PostgreSQL with JSON columns
- reCAPTCHA/hCaptcha integration

## Usage

1. Enter an Amazon product URL
2. Complete the captcha
3. View the analysis results including fake review percentage, letter grade, detailed explanation, and original vs adjusted ratings
4. Submit another URL as needed

## License

MIT
