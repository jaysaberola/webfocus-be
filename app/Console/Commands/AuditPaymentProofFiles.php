<?php

namespace App\Console\Commands;

use App\Models\CustomerPaymentProof;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class AuditPaymentProofFiles extends Command
{
    protected $signature = 'commerce:audit-payment-proofs {--fix-sample : Create payment-proofs/sample-receipt.txt if missing}';

    protected $description = 'Report payment-proof DB rows whose files are missing from storage/app/public';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $missing = 0;
        $ok = 0;

        if ($this->option('fix-sample') && ! $disk->exists('payment-proofs/sample-receipt.txt')) {
            $disk->put(
                'payment-proofs/sample-receipt.txt',
                "Sample payment receipt for provisioning tests.\n"
            );
            $this->info('Created storage/app/public/payment-proofs/sample-receipt.txt');
        }

        CustomerPaymentProof::query()
            ->orderBy('id')
            ->get(['id', 'proof_no', 'invoice_id', 'file_path', 'file_name', 'customer_id'])
            ->each(function (CustomerPaymentProof $proof) use ($disk, &$missing, &$ok) {
                $path = trim(str_replace('\\', '/', (string) ($proof->file_path ?? '')), '/');
                if ($path === '' || ! $disk->exists($path)) {
                    $missing++;
                    $this->warn(sprintf(
                        '#%d %s → MISSING: %s',
                        $proof->id,
                        $proof->proof_no ?: $proof->invoice_id,
                        $path !== '' ? $path : '(empty path)'
                    ));

                    return;
                }

                $ok++;
            });

        $this->line("Found {$ok} present, {$missing} missing.");
        $this->line('Public disk root: '.storage_path('app/public'));

        return $missing > 0 ? self::FAILURE : self::SUCCESS;
    }
}
