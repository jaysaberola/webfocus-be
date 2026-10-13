<?php

namespace App\Support;

use App\Models\SalesTransaction;
use App\Models\SalesTransactionProposal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DealConversationFile
{
    /**
     * @return array{name: string, url: string, path: string, kind: string}
     */
    public static function store(SalesTransaction $transaction, UploadedFile $file, string $from, ?int $uploaderId): array
    {
        $path = $file->store('deal-chat/'.$transaction->id, 'public');
        $name = $file->getClientOriginalName();
        $kind = 'file';

        if (
            $from === 'sales'
            && WebDesignQuotation::isWebDesign($transaction)
            && WebDesignQuotation::isPendingQuotation($transaction)
        ) {
            SalesTransactionProposal::create([
                'sales_transaction_id' => $transaction->id,
                'uploaded_by' => $uploaderId,
                'version' => 1,
                'kind' => 'proposal',
                'file_path' => $path,
                'file_name' => $name,
            ]);
            $transaction->notes = WebDesignQuotation::appendMarker(
                $transaction->notes,
                WebDesignQuotation::PROPOSAL_SUBMITTED
            );
            $kind = 'proposal';
        } elseif (
            $from === 'client'
            && WebDesignQuotation::isWebDesign($transaction)
            && WebDesignQuotation::hasMarker($transaction, WebDesignQuotation::PROPOSAL_SUBMITTED)
            && ! WebDesignQuotation::isPaymentRequested($transaction)
        ) {
            SalesTransactionProposal::create([
                'sales_transaction_id' => $transaction->id,
                'uploaded_by' => $uploaderId,
                'version' => 2,
                'kind' => 'signed',
                'file_path' => $path,
                'file_name' => $name,
            ]);
            $transaction->notes = WebDesignQuotation::appendMarker(
                $transaction->notes,
                WebDesignQuotation::PROPOSAL_SIGNED
            );
            $kind = 'signed';
        }

        return [
            'name' => $name,
            'url' => StorageUrl::publicAsset($path) ?: '',
            'path' => $path,
            'kind' => $kind,
        ];
    }

    public static function download(SalesTransaction $transaction, ?string $requested, bool $asAttachment = false): StreamedResponse
    {
        $path = self::resolveOwnedPath($transaction, $requested);
        abort_if($path === null || ! Storage::disk('public')->exists($path), 404, 'Attachment not found.');

        $name = basename($path);
        foreach (DealMeta::conversation($transaction->notes) as $row) {
            $file = $row['file'] ?? null;
            if (! is_array($file)) {
                continue;
            }
            if (self::filePath($file) === $path && trim((string) ($file['name'] ?? '')) !== '') {
                $name = (string) $file['name'];
                break;
            }
        }

        $disposition = ($asAttachment ? 'attachment' : 'inline').'; filename="'.$name.'"';

        return Storage::disk('public')->response($path, $name, [
            'Content-Disposition' => $disposition,
        ]);
    }

    public static function resolveOwnedPath(SalesTransaction $transaction, ?string $requested): ?string
    {
        $id = (int) $transaction->id;
        $requested = trim(str_replace('\\', '/', (string) $requested));
        $requested = ltrim(preg_replace('#^/?(?:storage/)?#', '', $requested) ?? $requested, '/');
        $requested = explode('?', $requested)[0] ?? $requested;

        if ($requested !== '' && self::ownsPath($id, $requested) && Storage::disk('public')->exists($requested)) {
            return $requested;
        }

        foreach (DealMeta::conversation($transaction->notes) as $row) {
            $file = is_array($row['file'] ?? null) ? $row['file'] : null;
            if ($file === null) {
                continue;
            }
            $path = self::filePath($file);
            $name = trim((string) ($file['name'] ?? ''));
            if (
                $requested !== ''
                && strcasecmp($name, $requested) !== 0
                && strcasecmp(basename((string) $path), $requested) !== 0
                && ! str_contains((string) ($file['url'] ?? ''), $requested)
            ) {
                continue;
            }
            if ($path !== '' && self::ownsPath($id, $path) && Storage::disk('public')->exists($path)) {
                return $path;
            }
        }

        $proposal = $transaction->proposals()
            ->when($requested !== '', function ($query) use ($requested) {
                $query->where('file_name', $requested)
                    ->orWhere('file_path', 'like', '%'.$requested);
            })
            ->latest()
            ->first();
        $proposalPath = trim((string) ($proposal?->file_path ?? ''));
        if ($proposalPath !== '' && self::ownsPath($id, $proposalPath) && Storage::disk('public')->exists($proposalPath)) {
            return $proposalPath;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $file
     */
    public static function filePath(array $file): string
    {
        $path = trim(str_replace('\\', '/', (string) ($file['path'] ?? '')));
        if ($path !== '') {
            return ltrim($path, '/');
        }

        $url = trim((string) ($file['url'] ?? ''));
        foreach (['deal-chat/', 'web-design-proposals/'] as $prefix) {
            if (! str_contains($url, $prefix)) {
                continue;
            }
            $extracted = $prefix.explode($prefix, $url, 2)[1];

            return explode('?', $extracted)[0] ?? $extracted;
        }

        return '';
    }

    private static function ownsPath(int $transactionId, string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        return str_starts_with($path, 'deal-chat/'.$transactionId.'/')
            || str_starts_with($path, 'web-design-proposals/'.$transactionId.'/');
    }
}
