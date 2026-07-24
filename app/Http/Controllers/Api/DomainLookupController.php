<?php

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Models\DomainTld;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DomainLookupController extends Controller
{
    private int $availabilityCacheMinutes = 10;
    private int $suggestionLimit = 20;

    public function index()
    {
        return view('domain-lookup.index');
    }

    public function check(Request $request)
{
    $validated = $request->validate([
        'name' => [
            'required',
            'string',
            'min:2',
            'max:63',
            'regex:/^(?!-)[a-z0-9-]+(?<!-)$/i',
        ],
        'tlds' => [
            'nullable',
            'array',
            'max:10',
        ],
        'tlds.*' => [
            'required',
            'string',
            'regex:/^\.[a-z0-9]+(?:\.[a-z0-9]+)*$/i',
        ],
    ]);

    $name = strtolower(trim($validated['name']));

    $requestedTlds = collect($validated['tlds'] ?? ['.com'])
        ->map(function ($tld) {
            return '.' . ltrim(strtolower(trim((string) $tld)), '.');
        })
        ->unique()
        ->take(10)
        ->values();

    $domains = $requestedTlds
        ->map(function (string $requestedTld) use ($name) {
            $domain = $name . $requestedTld;
            $matchedTld = $this->findMatchingTld($domain);

            return [
                'domain' => $domain,
                'tld' => $requestedTld,

                // Selling price from the local database
                'price' => $matchedTld
                    ? (float) ($matchedTld->category->selling_price ?? 0)
                    : 0,
            ];
        })
        ->values()
        ->toArray();

    /*
     * This reuses your existing parallel eNom checks,
     * WebNIC fallback, and availability cache.
     */
    $checkedDomains = $this->attachAvailabilityToSuggestionsFast($domains);

    $results = collect($checkedDomains)
        ->map(function (array $result) {
            return [
                'domain' => $result['domain'],
                'tld' => '.' . ltrim((string) $result['tld'], '.'),
                'available' => $result['available'] ?? null,
                'price' => (float) ($result['price'] ?? 0),

                // This is the selling-price currency shown to customers.
                'currency' => 'PHP',

                // Optional diagnostic/provider fields
                'provider' => $result['provider'] ?? null,
                'premium' => (bool) ($result['premium'] ?? false),
                'provider_currency' => $result['currency'] ?? null,
                'provider_register_price' =>
                    $result['provider_register_price'] ?? null,
                'code' => $result['rrpCode'] ?? null,
                'message' => $result['rrpText'] ?? null,
            ];
        })
        ->values();

    return response()->json([
        'query' => $name,
        'results' => $results,
        'checked_at' => now()->toIso8601String(),
    ]);
}

    public function search(Request $request)
    {
        $request->validate([
            'domain' => 'required|string|max:255',
        ]);

        $domain = $this->cleanDomain($request->domain);

        if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $domain)) {
            return back()
                ->with('error', 'Invalid domain format. Example: example.com')
                ->withInput();
        }

        $matchedTld = $this->findMatchingTld($domain);

        if (!$matchedTld) {
            return back()
                ->with('error', 'This domain extension is not yet supported.')
                ->withInput();
        }

        $sld = $this->extractSld($domain, $matchedTld->tld);

        /*
        |--------------------------------------------------------------------------
        | Main Domain Check
        |--------------------------------------------------------------------------
        | eNom remains the primary checker. WebNIC is only used as an optional
        | fallback when eNom cannot give a final available/taken answer.
        */
        $availability = $this->checkDomainAvailabilityCached($domain);

        /*
        |--------------------------------------------------------------------------
        | Suggestions
        |--------------------------------------------------------------------------
        | Suggestions still get prices from your database.
        | eNom parallel checks remain in place for speed.
        | WebNIC fallback is only called for unknown eNom results.
        */
        $suggestions = $this->generateSuggestions($sld, $domain);

        return view('domain-lookup.index', [
            'domain' => $domain,
            'sld' => $sld,
            'tld' => $matchedTld,
            'category' => $matchedTld->category,

            // Price from database
            'price' => $matchedTld->category->selling_price,

            // Availability from provider/cache
            'available' => $availability['available'],
            'rrpCode' => $availability['rrpCode'],
            'rrpText' => $availability['rrpText'],
            'provider' => $availability['provider'] ?? null,
            'premium' => $availability['premium'] ?? false,
            'currency' => $availability['currency'] ?? null,
            'provider_register_price' => $availability['provider_register_price'] ?? null,

            'suggestions' => $suggestions,
        ]);
    }

    private function cleanDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/^https?:\/\//', '', $domain);
        $domain = preg_replace('/^www\./', '', $domain);
        $domain = explode('/', $domain)[0];
        $domain = rtrim($domain, '/');

        return $domain;
    }

    private function findMatchingTld(string $domain)
    {
        static $tlds = null;

        if ($tlds === null) {
            $tlds = DomainTld::with('category')
                ->where('active', true)
                ->whereHas('category', function ($query) {
                    $query->where('active', true);
                })
                ->orderByRaw('LENGTH(tld) DESC')
                ->get();
        }

        foreach ($tlds as $tld) {
            if (str_ends_with($domain, '.' . $tld->tld)) {
                return $tld;
            }
        }

        return null;
    }

    private function extractSld(string $domain, string $tld): string
    {
        return preg_replace('/\.' . preg_quote($tld, '/') . '$/', '', $domain);
    }

    private function generateSuggestions(string $sld, string $originalDomain): array
    {
        $suggestions = [];

        $availableTlds = DomainTld::with('category')
            ->where('active', true)
            ->whereHas('category', function ($query) {
                $query->where('active', true);
            })
            ->get();

        foreach ($availableTlds as $domainTld) {
            $suggestedDomain = $sld . '.' . $domainTld->tld;

            if ($suggestedDomain !== $originalDomain) {
                $suggestions[] = $this->makeSuggestionArray(
                    $suggestedDomain,
                    $domainTld,
                    'Alternative Extension'
                );
            }
        }

        $prefixes = ['get', 'my', 'go', 'try', 'the'];
        $suffixes = ['online', 'shop', 'store', 'web', 'digital', 'ph'];

        $mainTlds = DomainTld::with('category')
            ->whereIn('tld', ['com', 'net', 'org', 'ph', 'com.ph'])
            ->where('active', true)
            ->whereHas('category', function ($query) {
                $query->where('active', true);
            })
            ->get();

        foreach ($prefixes as $prefix) {
            foreach ($mainTlds as $domainTld) {
                $suggestions[] = $this->makeSuggestionArray(
                    $prefix . $sld . '.' . $domainTld->tld,
                    $domainTld,
                    'Prefix Suggestion'
                );
            }
        }

        foreach ($suffixes as $suffix) {
            foreach ($mainTlds as $domainTld) {
                $suggestions[] = $this->makeSuggestionArray(
                    $sld . $suffix . '.' . $domainTld->tld,
                    $domainTld,
                    'Suffix Suggestion'
                );
            }
        }

        $suggestions = collect($suggestions)
            ->unique('domain')
            ->take($this->suggestionLimit)
            ->values()
            ->toArray();

        $suggestions = $this->attachAvailabilityToSuggestionsFast($suggestions);

        return collect($suggestions)
            ->sortByDesc(function ($suggestion) {
                return $suggestion['available'] === true ? 1 : 0;
            })
            ->values()
            ->toArray();
    }

    private function makeSuggestionArray(string $domain, DomainTld $domainTld, string $type): array
    {
        return [
            'domain' => $domain,
            'tld' => $domainTld->tld,
            'category' => $domainTld->category->name,

            // Price from database
            'price' => $domainTld->category->selling_price,
            'is_one_time' => $domainTld->category->is_one_time ?? false,

            'type' => $type,
        ];
    }

    private function checkDomainAvailabilityCached(string $domain): array
    {
        $domain = $this->cleanDomain($domain);
        $cacheKey = $this->availabilityCacheKey($domain);

        return Cache::remember($cacheKey, now()->addMinutes($this->availabilityCacheMinutes), function () use ($domain) {
            return $this->checkDomainAvailabilityUsingProviders($domain);
        });
    }

    private function availabilityCacheKey(string $domain): string
    {
        $domain = $this->cleanDomain($domain);

        // Include provider order so cached eNom-only checks do not conflict with eNom+WebNIC fallback checks.
        return 'domain_availability_' . md5($this->availabilityProviderCacheKey() . ':' . $domain);
    }

    private function availabilityProviderCacheKey(): string
    {
        return implode(',', $this->availabilityProviders());
    }

    private function availabilityProviders(): array
    {
        $providers = config('services.domain_lookup.providers', env('DOMAIN_AVAILABILITY_PROVIDERS', 'enom,webnic'));

        if (is_string($providers)) {
            $providers = explode(',', $providers);
        }

        if (!is_array($providers)) {
            return ['enom'];
        }

        $providers = collect($providers)
            ->map(function ($provider) {
                return strtolower(trim((string) $provider));
            })
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        return !empty($providers) ? $providers : ['enom'];
    }

    private function checkDomainAvailabilityUsingProviders(string $domain): array
    {
        $lastResult = null;

        foreach ($this->availabilityProviders() as $provider) {
            if ($provider === 'enom') {
                $result = $this->checkEnomAvailability($domain);
            } elseif ($provider === 'webnic') {
                $result = $this->checkWebnicAvailability($domain);
            } else {
                $result = $this->providerUnknown('Unsupported provider configured: ' . $provider, $provider);
            }

            $result['provider'] = $result['provider'] ?? $provider;

            // Stop once one provider gives a final answer.
            if (($result['available'] ?? null) !== null) {
                return $result;
            }

            $lastResult = $result;
        }

        return $lastResult ?: $this->providerUnknown('No configured domain availability provider.', 'none');
    }

    private function attachAvailabilityToSuggestionsFast(array $suggestions): array
    {
        $url = trim((string) config('services.enom.url'));
        $uid = trim((string) config('services.enom.uid'));
        $password = trim((string) config('services.enom.password'));

        $domainsToRequest = [];

        foreach ($suggestions as $key => $suggestion) {
            $domain = $this->cleanDomain($suggestion['domain']);
            $cacheKey = $this->availabilityCacheKey($domain);

            if (Cache::has($cacheKey)) {
                $cached = Cache::get($cacheKey);
                $this->applyAvailabilityResultToSuggestion($suggestions, $key, $cached);
                continue;
            }

            $matchedTld = $this->findMatchingTld($domain);

            if (!$matchedTld) {
                $result = $this->providerUnknown('Unsupported domain extension in your local database.', 'local');
                Cache::put($cacheKey, $result, now()->addMinutes($this->availabilityCacheMinutes));
                $this->applyAvailabilityResultToSuggestion($suggestions, $key, $result);
                continue;
            }

            $sld = $this->extractSld($domain, $matchedTld->tld);
            $enomTld = $this->normalizeTldForEnom($matchedTld->tld);

            if ($sld === '' || !preg_match('/^[a-z0-9-]+$/', $sld)) {
                $result = $this->providerUnknown('Invalid domain name. SLD sent: ' . $sld, 'local');
                Cache::put($cacheKey, $result, now()->addMinutes($this->availabilityCacheMinutes));
                $this->applyAvailabilityResultToSuggestion($suggestions, $key, $result);
                continue;
            }

            $domainsToRequest[$key] = [
                'domain' => $domain,
                'cache_key' => $cacheKey,
                'sld' => $sld,
                'tld' => $enomTld,
            ];
        }

        if (empty($domainsToRequest)) {
            return $suggestions;
        }

        if ($url === '' || $uid === '' || $password === '') {
            foreach ($domainsToRequest as $key => $requestData) {
                // Keep eNom as primary, but allow WebNIC fallback instead of immediately failing suggestions.
                $result = $this->checkDomainAvailabilityUsingProviders($requestData['domain']);

                if (($result['available'] ?? null) === null) {
                    $result = $this->providerUnknown('Missing ENOM_API_URL, ENOM_UID, or ENOM_PASSWORD in .env/config. ' . ($result['rrpText'] ?? ''), $result['provider'] ?? 'enom');
                }

                Cache::put($requestData['cache_key'], $result, now()->addMinutes($this->availabilityCacheMinutes));
                $this->applyAvailabilityResultToSuggestion($suggestions, $key, $result);
            }

            return $suggestions;
        }

        if ($uid === 'resellid' || $password === 'resellpw') {
            foreach ($domainsToRequest as $key => $requestData) {
                // Keep eNom as primary, but allow WebNIC fallback instead of immediately failing suggestions.
                $result = $this->checkDomainAvailabilityUsingProviders($requestData['domain']);

                if (($result['available'] ?? null) === null) {
                    $result = $this->providerUnknown('eNom credentials are still placeholders. Replace resellid/resellpw with your real eNom test credentials. ' . ($result['rrpText'] ?? ''), $result['provider'] ?? 'enom');
                }

                Cache::put($requestData['cache_key'], $result, now()->addMinutes($this->availabilityCacheMinutes));
                $this->applyAvailabilityResultToSuggestion($suggestions, $key, $result);
            }

            return $suggestions;
        }

        try {
            /*
            |--------------------------------------------------------------------------
            | Parallel eNom HTTP Requests
            |--------------------------------------------------------------------------
            | eNom stays as the fast primary suggestion checker.
            | WebNIC is called only for eNom unknown/unsupported results.
            */
            $responses = Http::pool(function ($pool) use ($domainsToRequest, $url, $uid, $password) {
                $poolRequests = [];

                foreach ($domainsToRequest as $key => $requestData) {
                    $poolRequests[$key] = $pool
                        ->timeout(8)
                        ->connectTimeout(5)
                        ->get($url, [
                            'command' => 'check',
                            'sld' => $requestData['sld'],
                            'tld' => $requestData['tld'],
                            'responsetype' => 'xml',
                            'uid' => $uid,
                            'pw' => $password,
                        ]);
                }

                return $poolRequests;
            });

            foreach ($domainsToRequest as $key => $requestData) {
                $response = $responses[$key] ?? null;

                if (!$response) {
                    $result = $this->enomUnknown('No response from eNom provider. TLD sent: ' . $requestData['tld']);
                } else {
                    $result = $this->parseEnomResponse($response, $requestData['tld']);
                }

                $result = $this->fallbackToWebnicIfNeeded($requestData['domain'], $result);

                Cache::put($requestData['cache_key'], $result, now()->addMinutes($this->availabilityCacheMinutes));
                $this->applyAvailabilityResultToSuggestion($suggestions, $key, $result);
            }
        } catch (\Throwable $e) {
            foreach ($domainsToRequest as $key => $requestData) {
                $result = $this->enomUnknown(get_class($e) . ': ' . $e->getMessage() . ' TLD sent: ' . $requestData['tld']);
                $result = $this->fallbackToWebnicIfNeeded($requestData['domain'], $result);

                Cache::put($requestData['cache_key'], $result, now()->addMinutes($this->availabilityCacheMinutes));
                $this->applyAvailabilityResultToSuggestion($suggestions, $key, $result);
            }
        }

        return $suggestions;
    }

    private function applyAvailabilityResultToSuggestion(array &$suggestions, int|string $key, array $result): void
    {
        $suggestions[$key]['available'] = $result['available'] ?? null;
        $suggestions[$key]['rrpCode'] = $result['rrpCode'] ?? 'No Code';
        $suggestions[$key]['rrpText'] = $result['rrpText'] ?? 'No provider message.';
        $suggestions[$key]['provider'] = $result['provider'] ?? null;
        $suggestions[$key]['premium'] = $result['premium'] ?? false;
        $suggestions[$key]['currency'] = $result['currency'] ?? null;
        $suggestions[$key]['provider_register_price'] = $result['provider_register_price'] ?? null;
    }

    private function fallbackToWebnicIfNeeded(string $domain, array $currentResult): array
    {
        if (($currentResult['available'] ?? null) !== null) {
            return $currentResult;
        }

        if (!in_array('webnic', $this->availabilityProviders(), true)) {
            return $currentResult;
        }

        $webnicResult = $this->checkWebnicAvailability($domain);

        if (($webnicResult['available'] ?? null) !== null) {
            return $webnicResult;
        }

        $currentResult['rrpText'] = trim(($currentResult['rrpText'] ?? '') . ' | WebNIC fallback: ' . ($webnicResult['rrpText'] ?? 'No WebNIC details.'));
        $currentResult['provider'] = $currentResult['provider'] ?? 'enom';

        return $currentResult;
    }

    private function checkEnomAvailability(string $domain): array
    {
        $domain = $this->cleanDomain($domain);
        $matchedTld = $this->findMatchingTld($domain);

        if (!$matchedTld) {
            return $this->enomUnknown('Unsupported domain extension in your local database.');
        }

        $sld = $this->extractSld($domain, $matchedTld->tld);
        $enomTld = $this->normalizeTldForEnom($matchedTld->tld);

        if ($sld === '' || !preg_match('/^[a-z0-9-]+$/', $sld)) {
            return $this->enomUnknown('Invalid domain name. SLD sent: ' . $sld);
        }

        $url = trim((string) config('services.enom.url'));
        $uid = trim((string) config('services.enom.uid'));
        $password = trim((string) config('services.enom.password'));

        if ($url === '') {
            return $this->enomUnknown('Missing ENOM_API_URL in .env/config.');
        }

        if ($uid === '' || $password === '') {
            return $this->enomUnknown('Missing ENOM_UID or ENOM_PASSWORD in .env/config.');
        }

        if ($uid === 'resellid' || $password === 'resellpw') {
            return $this->enomUnknown('eNom credentials are still placeholders. Replace resellid/resellpw with your real eNom test credentials.');
        }

        try {
            $response = Http::timeout(8)
                ->connectTimeout(5)
                ->get($url, [
                    'command' => 'check',
                    'sld' => $sld,
                    'tld' => $enomTld,
                    'responsetype' => 'xml',
                    'uid' => $uid,
                    'pw' => $password,
                ]);

            return $this->parseEnomResponse($response, $enomTld);
        } catch (\Throwable $e) {
            return $this->enomUnknown(get_class($e) . ': ' . $e->getMessage() . ' TLD sent: ' . $enomTld);
        }
    }

    private function checkWebnicAvailability(string $domain): array
    {
        $domain = $this->cleanDomain($domain);
        $matchedTld = $this->findMatchingTld($domain);

        if (!$matchedTld) {
            return $this->webnicUnknown('Unsupported domain extension in your local database.');
        }

        $endpoint = $this->webnicEndpoint();

        if ($endpoint === '') {
            return $this->webnicUnknown('Missing WEBNIC_API_URL in .env/config.');
        }

        $token = $this->getWebnicToken();

        if (!$token) {
            return $this->webnicUnknown('Unable to get WebNIC API token. Check WEBNIC_USERNAME/API key, WEBNIC_PASSWORD/API secret, token endpoint, and WebNIC IP whitelist.');
        }

        try {
            $params = [
                'domainName' => $domain,
            ];

            \Log::info('WEBNIC DOMAIN QUERY', [
                'endpoint' => $endpoint,
                'domainName_sent' => $domain,
                'auth' => 'bearer_token',
            ]);

            $response = $this->webnicAuthorizedHttpClient($token)
                ->get($endpoint, $params);

            return $this->parseWebnicResponse($response, $domain, $endpoint);
        } catch (\Throwable $e) {
            return $this->webnicUnknown(get_class($e) . ': ' . $e->getMessage() . ' Domain sent: ' . $domain);
        }
    }

    private function webnicEndpoint(): string
    {
        $url = trim((string) (config('services.webnic.base_url') ?: config('services.webnic.url')));

        if ($url === '') {
            return '';
        }

        if (str_ends_with($url, '/domain/v2/query')) {
            return $url;
        }

        return rtrim($url, '/') . '/domain/v2/query';
    }

    private function getWebnicToken(): ?string
    {
        $tokenUrl = trim((string) config('services.webnic.token_url'));

        // WebNIC token endpoint expects JSON fields named username and password.
        // In your account, username = API Key and password = API Secret.
        $username = trim((string) (config('services.webnic.username') ?: config('services.webnic.api_key')));
        $password = trim((string) (config('services.webnic.password') ?: config('services.webnic.api_secret')));

        if ($tokenUrl === '') {
            $baseUrl = trim((string) (config('services.webnic.base_url') ?: config('services.webnic.url')));
            $tokenUrl = $baseUrl !== '' ? rtrim($baseUrl, '/') . '/reseller/v2/api-user/token' : '';
        }

        if ($tokenUrl === '' || $username === '' || $password === '') {
            \Log::warning('WEBNIC TOKEN MISSING CONFIG', [
                'token_url_set' => $tokenUrl !== '',
                'username_set' => $username !== '',
                'password_set' => $password !== '',
            ]);

            return null;
        }

        $cacheKey = 'webnic_api_token_' . md5($tokenUrl . ':' . $username);
        $cachedToken = Cache::get($cacheKey);

        if (is_string($cachedToken) && trim($cachedToken) !== '') {
            return $cachedToken;
        }

        try {
            $response = Http::timeout(15)
                ->connectTimeout(8)
                ->acceptJson()
                ->asJson()
                ->post($tokenUrl, [
                    'username' => $username,
                    'password' => $password,
                ]);

            $json = $response->json();

            if (!is_array($json)) {
                $json = json_decode($response->body(), true);
            }

            \Log::info('WEBNIC TOKEN ATTEMPT', [
                'attempt' => 'json_username_password',
                'status' => $response->status(),
                'has_json' => is_array($json),
                'body_preview' => $this->sanitizeWebnicLogBody($response->body()),
            ]);

            if (!$response->successful() || !is_array($json)) {
                return null;
            }

            $token = $this->extractWebnicToken($json);

            if (!$token) {
                \Log::warning('WEBNIC TOKEN NOT FOUND IN RESPONSE', [
                    'status' => $response->status(),
                    'body_preview' => $this->sanitizeWebnicLogBody($response->body()),
                ]);

                return null;
            }

            $expiresIn = (int) (
                data_get($json, 'expiresIn')
                ?? data_get($json, 'expires_in')
                ?? data_get($json, 'data.expiresIn')
                ?? data_get($json, 'data.expires_in')
                ?? data_get($json, 'result.data.expiresIn')
                ?? data_get($json, 'result.data.expires_in')
                ?? 3600
            );

            $cacheMinutes = max(5, min(55, (int) floor(($expiresIn - 120) / 60)));
            Cache::put($cacheKey, $token, now()->addMinutes($cacheMinutes));

            return $token;
        } catch (\Throwable $e) {
            \Log::warning('WEBNIC TOKEN ATTEMPT FAILED', [
                'attempt' => 'json_username_password',
                'error' => get_class($e) . ': ' . $e->getMessage(),
            ]);
        }

        return null;
    }

    private function extractWebnicToken(array $json): ?string
    {
        $paths = [
            'token',
            'accessToken',
            'access_token',
            'jwt',
            'data.token',
            'data.accessToken',
            'data.access_token',
            'data.jwt',
            'result.token',
            'result.accessToken',
            'result.access_token',
            'result.data.token',
            'result.data.accessToken',
            'result.data.access_token',
            'result.data.jwt',
        ];

        foreach ($paths as $path) {
            $value = data_get($json, $path);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function webnicAuthorizedHttpClient(string $token)
    {
        $client = Http::timeout(15)
            ->connectTimeout(8)
            ->acceptJson();

        if (stripos($token, 'Bearer ') === 0) {
            return $client->withHeaders([
                'Authorization' => $token,
            ]);
        }

        return $client->withToken($token);
    }

    private function sanitizeWebnicLogBody(string $body): string
    {
        $body = mb_substr($body, 0, 500);

        $body = preg_replace('/("(?:token|accessToken|access_token|jwt)"\s*:\s*")[^"]+(")/i', '$1***REDACTED***$2', $body);

        return $body ?: '';
    }

    private function parseEnomResponse($response, string $enomTld): array
    {
        if (!$response->successful()) {
            return $this->enomUnknown('HTTP ' . $response->status() . ' from eNom provider. TLD sent: ' . $enomTld);
        }

        $body = trim($response->body());

        if ($body === '') {
            return $this->enomUnknown('Empty response body from eNom provider. TLD sent: ' . $enomTld);
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);

        if (!$xml) {
            $xmlErrors = collect(libxml_get_errors())
                ->map(function ($error) {
                    return trim($error->message);
                })
                ->filter()
                ->unique()
                ->implode('; ');

            libxml_clear_errors();

            return $this->enomUnknown('Invalid XML from eNom provider. ' . ($xmlErrors ?: 'No XML parse details.') . ' TLD sent: ' . $enomTld);
        }

        $rrpCode = trim((string) ($xml->RRPCode ?? ''));
        $rrpText = trim((string) ($xml->RRPText ?? ''));

        $responseString = trim((string) ($xml->ResponseString ?? ''));
        $errCount = trim((string) ($xml->ErrCount ?? ''));

        $errors = [];
        $errorNodes = $xml->xpath('//errors/*');

        if ($errorNodes) {
            foreach ($errorNodes as $errorNode) {
                $errorText = trim((string) $errorNode);
                if ($errorText !== '') {
                    $errors[] = $errorText;
                }
            }
        }

        $fallbackTextParts = array_filter([
            $rrpText,
            $responseString,
            !empty($errors) ? implode('; ', $errors) : null,
            $errCount !== '' ? 'ErrCount: ' . $errCount : null,
        ]);

        $finalText = trim(implode(' | ', $fallbackTextParts));

        if ($rrpCode === '210') {
            return [
                'available' => true,
                'rrpCode' => $rrpCode,
                'rrpText' => $finalText !== '' ? $finalText : 'Domain available',
                'provider' => 'enom',
            ];
        }

        if ($rrpCode === '211') {
            return [
                'available' => false,
                'rrpCode' => $rrpCode,
                'rrpText' => $finalText !== '' ? $finalText : 'Domain not available',
                'provider' => 'enom',
            ];
        }

        if ($rrpCode === '827') {
            return [
                'available' => null,
                'rrpCode' => $rrpCode,
                'rrpText' => 'TLD .' . $enomTld . ' is not supported by your current eNom test/live account. TLD sent: ' . $enomTld,
                'provider' => 'enom',
            ];
        }

        return [
            'available' => null,
            'rrpCode' => $rrpCode !== '' ? $rrpCode : 'No Code',
            'rrpText' => ($finalText !== '' ? $finalText : 'eNom did not return RRPCode/RRPText.') . ' TLD sent: ' . $enomTld,
            'provider' => 'enom',
        ];
    }

    private function parseWebnicResponse($response, string $domain, ?string $endpoint = null): array
    {
        if (!$response->successful()) {
            return $this->webnicUnknown(
                'HTTP ' . $response->status() .
                ' from WebNIC provider.' .
                ($endpoint ? ' Endpoint: ' . $endpoint : '') .
                ' Domain sent: ' . $domain .
                ' Body: ' . mb_substr($response->body(), 0, 500)
            );
        }

        $body = trim($response->body());

        if ($body === '') {
            return $this->webnicUnknown('Empty response body from WebNIC provider. Domain sent: ' . $domain);
        }

        $json = $response->json();

        if (!is_array($json)) {
            $json = json_decode($body, true);
        }

        if (!is_array($json)) {
            return $this->webnicUnknown('Invalid JSON from WebNIC provider. Domain sent: ' . $domain . ' Body: ' . mb_substr($body, 0, 300));
        }

        $result = $json['result'] ?? $json;
        $data = $result['data'] ?? ($json['data'] ?? []);

        $code = trim((string) ($result['code'] ?? $json['code'] ?? ''));
        $status = trim((string) ($result['status'] ?? $data['status'] ?? $json['status'] ?? ''));
        $message = trim((string) ($result['message'] ?? $json['message'] ?? ''));

        $availableRaw = $data['available'] ?? $data['status'] ?? $status;
        $available = $this->normalizeWebnicAvailableValue($availableRaw, $message);

        $premium = filter_var($data['premium'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $currency = $data['currency'] ?? null;
        $registerPrice = $data['register'] ?? $data['registerPrice'] ?? null;

        $textParts = array_filter([
            $message,
            $status !== '' ? 'Status: ' . $status : null,
            $premium ? 'Premium domain' : null,
            $currency && $registerPrice ? 'Provider price: ' . $currency . ' ' . $registerPrice : null,
        ]);

        return [
            'available' => $available,
            'rrpCode' => $code !== '' ? $code : 'WEBNIC',
            'rrpText' => !empty($textParts) ? implode(' | ', $textParts) : 'WebNIC response parsed.',
            'provider' => 'webnic',
            'premium' => $premium,
            'currency' => $currency,
            'provider_register_price' => $registerPrice,
        ];
    }

    private function normalizeWebnicAvailableValue($availableRaw, string $message): ?bool
    {
        if (is_bool($availableRaw)) {
            return $availableRaw;
        }

        if (is_numeric($availableRaw)) {
            return ((int) $availableRaw) === 1;
        }

        $value = strtolower(trim((string) $availableRaw));
        $message = strtolower(trim($message));
        $combined = trim($value . ' ' . $message);

        if (in_array($value, ['available', 'true', 'yes', 'y'], true)) {
            return true;
        }

        if (in_array($value, ['unavailable', 'taken', 'registered', 'false', 'no', 'n'], true)) {
            return false;
        }

        if (str_contains($combined, 'not available') || str_contains($combined, 'already registered') || str_contains($combined, 'registered by')) {
            return false;
        }

        if (str_contains($combined, 'not registered') || str_contains($combined, 'available')) {
            return true;
        }

        return null;
    }

    private function normalizeTldForEnom(?string $tld): string
    {
        $tld = strtolower(trim((string) $tld));
        $tld = ltrim($tld, '.');

        return $tld;
    }

    private function enomUnknown(string $message): array
    {
        return [
            'available' => null,
            'rrpCode' => 'No Code',
            'rrpText' => $message,
            'provider' => 'enom',
        ];
    }

    private function webnicUnknown(string $message): array
    {
        return [
            'available' => null,
            'rrpCode' => 'WEBNIC_UNKNOWN',
            'rrpText' => $message,
            'provider' => 'webnic',
        ];
    }

    private function providerUnknown(string $message, string $provider): array
    {
        return [
            'available' => null,
            'rrpCode' => 'No Code',
            'rrpText' => $message,
            'provider' => $provider,
        ];
    }
}
