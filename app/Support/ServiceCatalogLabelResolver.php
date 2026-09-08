<?php

namespace App\Support;

class ServiceCatalogLabelResolver
{
    /**
     * @return array{
     *     service_name: ?string,
     *     plan_name: ?string,
     *     subject: ?string,
     *     product_category: ?string,
     *     domain: ?string
     * }
     */
    public static function describe(
        ?string $title,
        ?string $category = null,
        ?string $plan = null,
        ?string $website = null,
    ): array {
        $title = trim((string) $title);
        $category = trim((string) $category);
        $plan = trim((string) $plan);
        $raw = $plan !== '' ? $plan : $title;
        $haystack = strtolower(trim($raw.' '.$category.' '.$title));
        $domain = self::resolveDomain($title, $plan, $website);

        if (self::isEset($haystack)) {
            return self::payload(null, null, 'ESET Endpoint Protection', 'ESET PROTECT Advanced Cloud', $domain);
        }

        if (self::isAddon($haystack, $category)) {
            return self::payload('Add-ons', 'Add-ons', 'Add-ons', self::addonProductCategory($raw, $title), $domain);
        }

        if (self::isDomainService($haystack, $raw, $title, $category)) {
            $domainValue = $domain ?: (TransactionLabelResolver::looksLikeDomain($raw) ? self::formatDomain($raw) : null);
            $productCategory = self::domainProductCategory($domainValue ?: $raw);

            return self::payload(
                'Domain',
                self::domainPlanName($productCategory),
                'Domain Registration',
                $productCategory,
                $domainValue,
            );
        }

        if (self::isWebDev($haystack)) {
            return self::payload('Web Dev', 'Custom WebDev', 'Custom Web Dev', 'Web Development - Piecemeal', $domain);
        }

        return self::describeHosting($raw, $title, $category, $haystack, $domain);
    }

    /**
     * @return array{
     *     service_name: ?string,
     *     plan_name: ?string,
     *     subject: ?string,
     *     product_category: ?string,
     *     domain: ?string
     * }
     */
    private static function describeHosting(
        string $raw,
        string $title,
        string $category,
        string $haystack,
        ?string $domain,
    ): array {
        if (preg_match('/cloud\s*micro|micro\s*server/i', $haystack)) {
            return self::payload('Hosting', 'Cloud Hosting', 'CLOUD MICRO SERVER', null, $domain);
        }
        if (preg_match('/cloud\s*business|business\s*enterprise/i', $haystack)) {
            return self::payload('Hosting', 'Cloud Hosting', 'CLOUD BUSINESS ENTERPRISE', null, $domain);
        }
        if (str_contains($haystack, 'cloud')) {
            return self::payload('Hosting', 'Cloud Hosting', self::prettyLabel($raw) ?: 'Cloud Hosting', null, $domain);
        }

        if (preg_match('/bare\s*-?\s*metal|baremetal/i', $haystack)) {
            $isWindows = str_contains($haystack, 'windows');
            $subject = $isWindows ? 'BareMetal_Windows' : 'BareMetal_Linux';
            if (str_contains($haystack, 'dedicated')) {
                return self::payload('Hosting', 'Dedicated Server', 'Dedicated '.$subject, null, $domain);
            }

            return self::payload('Hosting', 'Bare-Metal Server', $subject, null, $domain);
        }

        if (preg_match('/dedicated|baremetal|essential|corporate|enterprise|professional|premium/i', $haystack)
            && ! preg_match('/shared|starter|deluxe|standard shared/i', $haystack)
        ) {
            return self::describeDedicated($haystack, $raw, $domain);
        }

        if (preg_match('/shared|starter|deluxe|linux cloud|windows cloud|web hosting/i', $haystack)
            || strcasecmp($category, 'Shared Hosting') === 0
        ) {
            return self::describeShared($haystack, $raw, $domain);
        }

        if (strcasecmp($category, 'Dedicated Server') === 0 || strcasecmp($category, 'Dedicated Hosting') === 0) {
            return self::describeDedicated($haystack, $raw, $domain);
        }

        if ($raw !== '' || $title !== '') {
            return self::payload('Hosting', self::prettyLabel($category) ?: 'Hosting', self::prettyLabel($raw ?: $title), null, $domain);
        }

        return self::payload(null, null, null, null, $domain);
    }

    /**
     * @return array{
     *     service_name: ?string,
     *     plan_name: ?string,
     *     subject: ?string,
     *     product_category: ?string,
     *     domain: ?string
     * }
     */
    private static function describeShared(string $haystack, string $raw, ?string $domain): array
    {
        if (preg_match('/starter/i', $haystack)) {
            return self::payload('Hosting', 'Shared Hosting', 'Starter', null, $domain);
        }
        if (preg_match('/deluxe/i', $haystack)) {
            return self::payload('Hosting', 'Shared Hosting', 'Deluxe', 'Linux Cloud Deluxe, Windows Cloud Deluxe', $domain);
        }
        if (preg_match('/business/i', $haystack) && ! str_contains($haystack, 'enterprise')) {
            return self::payload('Hosting', 'Shared Hosting', 'Business', 'Linux Cloud Business, Windows Cloud Business', $domain);
        }
        if (preg_match('/standard|linux cloud/i', $haystack)) {
            return self::payload('Hosting', 'Shared Hosting', 'Standard', 'Linux Cloud Standard', $domain);
        }

        return self::payload(
            'Hosting',
            'Shared Hosting',
            preg_match('/web hosting/i', $haystack) ? 'Web Hosting - Shared' : (self::prettyLabel($raw) ?: 'Web Hosting - Shared'),
            'Linux Cloud Standard',
            $domain,
        );
    }

    /**
     * @return array{
     *     service_name: ?string,
     *     plan_name: ?string,
     *     subject: ?string,
     *     product_category: ?string,
     *     domain: ?string
     * }
     */
    private static function describeDedicated(string $haystack, string $raw, ?string $domain): array
    {
        if (preg_match('/essential/i', $haystack)) {
            return self::payload('Hosting', 'Dedicated Server', 'Dedicated_Essential', 'Dedicated Cloud Custom', $domain);
        }
        if (preg_match('/business/i', $haystack) && ! str_contains($haystack, 'enterprise')) {
            return self::payload('Hosting', 'Dedicated Server', 'Dedicated_Business', 'Dedicated Cloud Business', $domain);
        }
        if (preg_match('/premium/i', $haystack)) {
            return self::payload('Hosting', 'Dedicated Server', 'Dedicated_Premium', 'Dedicated Cloud Premium', $domain);
        }
        if (preg_match('/professional/i', $haystack)) {
            return self::payload('Hosting', 'Dedicated Server', 'Dedicated_Professional', 'Dedicated Cloud Professional', $domain);
        }
        if (preg_match('/corporate/i', $haystack)) {
            return self::payload('Hosting', 'Dedicated Server', 'Dedicated_Corporate', null, $domain);
        }
        if (preg_match('/enterprise/i', $haystack)) {
            return self::payload('Hosting', 'Dedicated Server', 'Dedicated_Enterprise', null, $domain);
        }

        return self::payload(
            'Hosting',
            'Dedicated Server',
            self::prettyLabel($raw) ?: 'Dedicated Server',
            str_contains($haystack, 'custom') ? 'Dedicated Cloud Custom' : null,
            $domain,
        );
    }

    private static function isAddon(string $haystack, string $category): bool
    {
        if (strcasecmp($category, 'Add-on') === 0 || strcasecmp($category, 'Add on') === 0 || strcasecmp($category, 'Add-ons') === 0) {
            return true;
        }

        return (bool) preg_match(
            '/add[-\s]?ons?|static ip|imunify|immunify|magic spam|sitelock|codeguard|whois|wildcard ssl|standard ssl|secure socket layer|\bssl\b|data cap|gigabit lan|control panel|cpanel|plesk|daily back-?up|ms sql|\bstorage\b/i',
            $haystack
        );
    }

    private static function isDomainService(string $haystack, string $raw, string $title, string $category): bool
    {
        if (strcasecmp($category, 'Domains') === 0 || strcasecmp($category, 'Domain') === 0) {
            return true;
        }

        if (TransactionLabelResolver::looksLikeDomain($raw) || TransactionLabelResolver::looksLikeDomain($title)) {
            return true;
        }

        return str_contains($haystack, 'domain') && ! str_contains($haystack, 'dedicated');
    }

    private static function isWebDev(string $haystack): bool
    {
        return (bool) preg_match(
            '/web\s*dev|web\s*design|custom web|piecemeal|figma|canvas|business starter|professional corporate|e-?commerce plus|starter launch|website template/i',
            $haystack
        );
    }

    private static function isEset(string $haystack): bool
    {
        return str_contains($haystack, 'eset');
    }

    private static function addonProductCategory(string $raw, string $title): string
    {
        $haystack = strtolower($raw.' '.$title);

        $map = [
            'whois' => 'Add Ons_WhoIs',
            'static ip' => 'Add On - Static IP',
            'dedicated ip' => 'Add On - Static IP',
            'sitelock' => 'Add Ons_Sitelock',
            'codeguard' => 'Add Ons_Codeguard',
            'magic spam' => 'Add On - Magic Spam Pro',
            'imunify' => 'Add On - Immunify360',
            'immunify' => 'Add On - Immunify360',
            'wildcard ssl' => 'Add On - Wildcard SSL',
            'standard ssl' => 'Secure Socket Layer (Standard SSL)',
            'secure socket layer (wildcard' => 'Secure Socket Layer (Wildcard SSL)',
            'secure socket layer (standard' => 'Secure Socket Layer (Standard SSL)',
            '1 gb storage' => 'Shared_Additional 1 GB Storage',
            '10 gb data' => 'Shared_Additional 10 GB Data Cap',
            'ms sql database for windows' => 'Shared_MS SQL Database for Windows',
            'daily back-up' => str_contains($haystack, '1.5') || str_contains($haystack, 'bare')
                ? 'Bare Metal_Daily Back-Up with Retention of 3 Back-ups up to 1.5 TB'
                : 'Dedicated_Daily Back-Up with Retention of 3 Back-ups up to 150 GB',
            'control panel for linux' => 'Bare Metal_Control Panel for Linux (cPanel)',
            'cpanel' => 'Bare Metal_Control Panel for Linux (cPanel)',
            'parallel plesk' => 'Bare Metal_Control Panel for Windows (Parallel Plesk)',
            'plesk' => 'Bare Metal_Control Panel for Windows (Parallel Plesk)',
            'ms sql 2012' => 'Bare Metal_MS SQL 2012/2016 Web Edition',
            'gigabit lan' => 'Bare Metal_Gigabit LAN',
            'storage' => 'Add On - Storage',
            'ssl' => 'Secure Socket Layer (Standard SSL)',
        ];

        foreach ($map as $needle => $label) {
            if (str_contains($haystack, $needle)) {
                return $label;
            }
        }

        $cleaned = preg_replace('/^add[-\s]?ons?[_\s-]*/i', '', $raw) ?? $raw;

        return $cleaned !== '' ? $cleaned : 'Add-ons';
    }

    private static function domainProductCategory(string $value): string
    {
        $haystack = strtolower($value);

        if (preg_match('/edu\.ph|\.edu\b|education/i', $haystack)) {
            return 'Educational Domain';
        }
        if (preg_match('/gov\.ph|\.gov\b|government/i', $haystack)) {
            return 'Government Domains (one-time registration) .gov.ph';
        }
        if (preg_match('/\.(com\.ph|net\.ph|org\.ph|ph)\b|country/i', $haystack)) {
            return 'Country Level Domains (.ph .com.ph .net.ph .org.ph)';
        }
        if (preg_match('/\.(biz|info|mobi|pro|asia|online)\b|hybrid/i', $haystack)) {
            return 'Hybrid Top Level Domains (.biz .info .mobi, .pro, .asia, .online)';
        }

        return 'Top Level Domain';
    }

    private static function domainPlanName(string $productCategory): ?string
    {
        if (str_contains($productCategory, 'Educational') || str_contains($productCategory, 'Education')) {
            return 'Education Domains';
        }
        if (str_contains($productCategory, 'Government')) {
            return null;
        }
        if (str_contains($productCategory, 'Top Level Domain') && ! str_contains($productCategory, 'Hybrid')) {
            return 'Standard';
        }

        return null;
    }

    private static function resolveDomain(?string $title, ?string $plan, ?string $website): ?string
    {
        foreach ([$title, $plan] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && TransactionLabelResolver::looksLikeDomain($candidate)) {
                return self::formatDomain($candidate);
            }
        }

        $website = trim((string) $website);
        if ($website === '') {
            return null;
        }

        $clean = preg_replace('#^https?://#i', '', $website) ?? $website;
        $clean = explode('/', $clean)[0] ?? '';
        $clean = trim($clean);

        return $clean !== '' ? self::formatDomain($clean) : null;
    }

    private static function formatDomain(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('#^https?://#i', '', $value) ?? $value;
        $value = explode('/', $value)[0] ?? $value;

        return trim($value);
    }

    private static function prettyLabel(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return $value;
    }

    /**
     * @return array{
     *     service_name: ?string,
     *     plan_name: ?string,
     *     subject: ?string,
     *     product_category: ?string,
     *     domain: ?string
     * }
     */
    private static function payload(
        ?string $serviceName,
        ?string $planName,
        ?string $subject,
        ?string $productCategory,
        ?string $domain,
    ): array {
        return [
            'service_name' => self::nullable($serviceName),
            'plan_name' => self::nullable($planName),
            'subject' => self::nullable($subject),
            'product_category' => self::nullable($productCategory),
            'domain' => self::nullable($domain),
        ];
    }

    private static function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
