<?php

namespace App\Services;

class GrapesParser
{
    /**
     * Parse a GrapesJS export payload into html, css and js parts.
     * Accepts either JSON with keys (html, css, js) or an HTML string
     * containing <style> and <script> blocks.
     *
     * @param string|null $payload
     * @return array{grapes_html:string|null,grapes_css:string|null,grapes_js:string|null}
     */
    public static function parse(?string $payload): array
    {
        $html = null;
        $css = null;
        $js = null;

        if (is_null($payload) || trim($payload) === '') {
            return ['grapes_html' => null, 'grapes_css' => null, 'grapes_js' => null];
        }

        // Try JSON decode first
        $decoded = json_decode($payload, true);
        if (is_array($decoded)) {
            // Common keys from Grapes exports or custom frontend: html, css, js, style, script
            $html = $decoded['html'] ?? $decoded['gjs-html'] ?? $decoded['content'] ?? null;
            $css  = $decoded['css'] ?? $decoded['gjs-css'] ?? $decoded['style'] ?? null;
            $js   = $decoded['js'] ?? $decoded['gjs-js'] ?? $decoded['script'] ?? null;

            // If keys are present but empty strings, normalize to null
            $html = (is_string($html) && trim($html) !== '') ? $html : $html ?? null;
            $css  = (is_string($css)  && trim($css)  !== '') ? $css  : $css  ?? null;
            $js   = (is_string($js)   && trim($js)   !== '') ? $js   : $js   ?? null;

            return ['grapes_html' => $html, 'grapes_css' => $css, 'grapes_js' => $js];
        }

        // Not JSON: treat it as HTML and extract <style> and <script>
        $payload = (string) $payload;

        // Extract all <style>...</style> blocks
        $cssMatches = [];
        if (preg_match_all('#<style[^>]*>(.*?)</style>#is', $payload, $matches)) {
            foreach ($matches[1] as $m) {
                $cssMatches[] = $m;
            }
        }

        // Extract all <script>...</script> blocks
        $jsMatches = [];
        if (preg_match_all('#<script[^>]*>(.*?)</script>#is', $payload, $matches)) {
            foreach ($matches[1] as $m) {
                $jsMatches[] = $m;
            }
        }

        // Remove style and script tags from payload to produce the HTML part
        $htmlPart = preg_replace('#<(?:script|style)[^>]*>.*?</(?:script|style)>#is', '', $payload);

        $cssPart = count($cssMatches) ? implode("\n\n", array_map('trim', $cssMatches)) : null;
        $jsPart  = count($jsMatches)  ? implode("\n\n", array_map('trim', $jsMatches))  : null;

        $htmlPart = trim($htmlPart);

        return [
            'grapes_html' => $htmlPart !== '' ? $htmlPart : null,
            'grapes_css'  => $cssPart,
            'grapes_js'   => $jsPart,
        ];
    }
}
