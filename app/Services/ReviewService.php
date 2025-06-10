<?php

namespace App\Services;

use App\Models\AsinData;

/**
 * Review Service for Amazon product URL processing
 *
 * This service handles the extraction of ASINs from Amazon URLs,
 * country detection, and database lookups for existing analyses.
 */
class ReviewService
{
    /**
     * Extract the 10-character ASIN from the Amazon product URL.
     *
     * @param string $url The Amazon product URL
     * @return string The extracted ASIN
     * @throws \InvalidArgumentException If ASIN cannot be extracted
     */
    public function extractAsin(string $url): string
    {
        if (preg_match('/\/dp\/([A-Z0-9]{10})/', $url, $matches)) {
            return $matches[1];
        }
        if (preg_match('/\/product\/([A-Z0-9]{10})/', $url, $matches)) {
            return $matches[1];
        }
        throw new \InvalidArgumentException('Invalid Amazon product URL');
    }

    /**
     * Extract country code from Amazon URL based on domain.
     *
     * @param string $url The Amazon product URL
     * @return string Two-letter country code (defaults to 'us')
     */
    public function extractCountryFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        
        $countryMap = [
            'amazon.com'      => 'us',
            'amazon.co.uk'    => 'gb',
            'amazon.ca'       => 'ca',
            'amazon.com.au'   => 'au',
            'amazon.de'       => 'de',
            'amazon.fr'       => 'fr',
            'amazon.it'       => 'it',
            'amazon.es'       => 'es',
            'amazon.in'       => 'in',
            'amazon.co.jp'    => 'jp',
            'amazon.com.mx'   => 'mx',
            'amazon.com.br'   => 'br',
            'amazon.nl'       => 'nl',
            'amazon.sg'       => 'sg',
            'amazon.com.tr'   => 'tr',
            'amazon.ae'       => 'ae',
            'amazon.sa'       => 'sa',
            'amazon.se'       => 'se',
            'amazon.pl'       => 'pl',
            'amazon.eg'       => 'eg',
            'amazon.be'       => 'be',
        ];

        foreach ($countryMap as $domain => $code) {
            if (stripos($host, $domain) !== false) {
                return $code;
            }
        }

        return 'us'; // Default to US
    }

    /**
     * Find existing analysis in database by ASIN and country.
     *
     * @param string $asin The Amazon Standard Identification Number
     * @param string $country Two-letter country code
     * @return AsinData|null The existing analysis or null if not found
     */
    public function findExistingAnalysis(string $asin, string $country): ?AsinData
    {
        return AsinData::where('asin', $asin)
            ->where('country', $country)
            ->first();
    }
}
