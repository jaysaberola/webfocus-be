<?php

namespace App\Services;

use App\Models\PaynamicsPaymentReference;
use App\Models\SalesTransaction;
use App\Support\PaynamicsProofEvaluator;
use App\Support\WebDesignQuotation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use OpenAI;
use Throwable;

class PaynamicsProofScanner
{
    /**
     * @return array{valid: bool, code: string, message: string, has_brand: bool, has_amount: bool, has_success: bool, has_date: bool, matched_request_id: ?string}
     */
    public function scan(UploadedFile $file, SalesTransaction $transaction, ?string $clientText = null): array
    {
        $extracted = $this->extractText($file);
        $text = PaynamicsProofEvaluator::looksReadable($extracted) ? $extracted : trim((string) $clientText);
        $amount = WebDesignQuotation::displayAmount($transaction);
        $references = $transaction->paynamicsPaymentReferences()->get(['request_id', 'paid_at']);
        $requestIds = $references
            ->pluck('request_id')
            ->filter()
            ->values()
            ->all();
        $foreignRequestIds = PaynamicsPaymentReference::query()
            ->where('sales_transaction_id', '!=', $transaction->id)
            ->pluck('request_id')
            ->filter()
            ->values()
            ->all();
        $expectedPaidAts = $references
            ->pluck('paid_at')
            ->filter()
            ->values()
            ->all();

        return PaynamicsProofEvaluator::evaluate($text, $amount, $requestIds, $foreignRequestIds, $expectedPaidAts);
    }

    private function extractText(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return '';
        }

        $mime = strtolower((string) $file->getMimeType());
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $isPdf = str_contains($mime, 'pdf') || $extension === 'pdf';

        if ($isPdf) {
            $pdfText = $this->extractPdfText($path);
            if (PaynamicsProofEvaluator::looksReadable($pdfText)) {
                return $pdfText;
            }

            $rendered = $this->renderPdfFirstPage($path);
            if ($rendered !== null) {
                $imageText = $this->extractImageText($rendered, 'image/png');
                @unlink($rendered);

                if (PaynamicsProofEvaluator::looksReadable($imageText)) {
                    return $imageText;
                }
            }
        }

        return $this->extractImageText($path, $mime);
    }

    private function extractPdfText(string $path): string
    {
        $fromBinary = $this->extractPdfStrings($path);
        if (PaynamicsProofEvaluator::looksReadable($fromBinary)) {
            return $fromBinary;
        }

        $pdftotext = $this->resolveBinary('pdftotext');
        if ($pdftotext === null) {
            return $fromBinary;
        }

        $out = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('paynamics-pdf-', true) . '.txt';
        $cmd = $this->escapeCommand($pdftotext) . ' -q ' . escapeshellarg($path) . ' ' . escapeshellarg($out);
        $this->runCommand($cmd);

        $text = is_file($out) ? (string) file_get_contents($out) : '';
        if (is_file($out)) {
            @unlink($out);
        }

        return trim($text) !== '' ? $text : $fromBinary;
    }

    private function extractPdfStrings(string $path): string
    {
        $raw = (string) file_get_contents($path);
        if ($raw === '') {
            return '';
        }

        $chunks = [];
        if (preg_match_all('/\((?:\\\\.|[^\\\\)]){3,}\)/', $raw, $matches)) {
            foreach ($matches[0] as $match) {
                $chunks[] = stripcslashes(trim($match, '()'));
            }
        }

        if (preg_match_all('/<([0-9A-Fa-f]{8,})>/', $raw, $hexMatches)) {
            foreach ($hexMatches[1] as $hex) {
                $binary = @hex2bin($hex);
                if (is_string($binary) && $binary !== '') {
                    $chunks[] = $binary;
                }
            }
        }

        return $this->cleanExtractedText(implode("\n", $chunks));
    }

    private function extractImageText(string $path, string $mime): string
    {
        $fromTesseract = $this->tesseractText($path);
        if (PaynamicsProofEvaluator::looksReadable($fromTesseract)) {
            return $fromTesseract;
        }

        $fromVision = $this->openAiVisionText($path, $mime);
        if (PaynamicsProofEvaluator::looksReadable($fromVision)) {
            return $fromVision;
        }

        return trim($fromTesseract) !== '' ? $fromTesseract : $fromVision;
    }

    private function tesseractText(string $path): string
    {
        $binary = $this->resolveBinary((string) config('paynamics.tesseract_path', 'tesseract'));
        if ($binary === null) {
            return '';
        }

        $outBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('paynamics-ocr-', true);
        $cmd = $this->escapeCommand($binary) . ' ' . escapeshellarg($path) . ' ' . escapeshellarg($outBase) . ' -l eng --psm 6';
        $this->runCommand($cmd);

        $out = $outBase . '.txt';
        $text = is_file($out) ? (string) file_get_contents($out) : '';
        if (is_file($out)) {
            @unlink($out);
        }

        return $this->cleanExtractedText($text);
    }

    private function openAiVisionText(string $path, string $mime): string
    {
        $key = (string) config('services.openai.key');
        if ($key === '' || ! is_file($path)) {
            return '';
        }

        $mime = str_starts_with($mime, 'image/') ? $mime : 'image/jpeg';
        $binary = file_get_contents($path);
        if (! is_string($binary) || $binary === '') {
            return '';
        }

        try {
            $client = OpenAI::client($key);
            $response = $client->chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Extract all visible text from this payment receipt or screenshot. Return plain text only, no commentary.',
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:' . $mime . ';base64,' . base64_encode($binary),
                            ],
                        ],
                    ],
                ]],
                'temperature' => 0,
                'max_tokens' => 1200,
            ]);

            return $this->cleanExtractedText((string) ($response->choices[0]->message->content ?? ''));
        } catch (Throwable $exception) {
            Log::warning('Paynamics proof vision scan failed.', [
                'error' => $exception->getMessage(),
            ]);

            return '';
        }
    }

    private function renderPdfFirstPage(string $path): ?string
    {
        if (! class_exists(\Imagick::class)) {
            return null;
        }

        try {
            $image = new \Imagick();
            $image->setResolution(140, 140);
            $image->readImage($path . '[0]');
            $image->setImageFormat('png');
            $out = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('paynamics-pdf-page-', true) . '.png';
            $image->writeImage($out);
            $image->clear();
            $image->destroy();

            return is_file($out) ? $out : null;
        } catch (Throwable $exception) {
            Log::info('Paynamics proof PDF render skipped.', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function cleanExtractedText(string $text): string
    {
        $value = preg_replace('/[^\p{L}\p{N}\p{P}\p{Z}₱]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private function resolveBinary(string $binary): ?string
    {
        $binary = trim($binary);
        if ($binary === '') {
            return null;
        }

        if ((str_contains($binary, DIRECTORY_SEPARATOR) || str_contains($binary, '/')) && is_file($binary)) {
            return $binary;
        }

        $finder = PHP_OS_FAMILY === 'Windows' ? 'where' : 'command -v';
        $output = [];
        $code = 1;
        exec($finder . ' ' . escapeshellarg($binary) . (PHP_OS_FAMILY === 'Windows' ? ' 2>NUL' : ' 2>/dev/null'), $output, $code);

        return $code === 0 && isset($output[0]) && $output[0] !== '' ? $output[0] : null;
    }

    private function escapeCommand(string $binary): string
    {
        return PHP_OS_FAMILY === 'Windows' ? '"' . $binary . '"' : escapeshellarg($binary);
    }

    private function runCommand(string $command): void
    {
        $output = [];
        $code = 0;
        exec($command . (PHP_OS_FAMILY === 'Windows' ? ' 2>NUL' : ' 2>/dev/null'), $output, $code);
    }
}
