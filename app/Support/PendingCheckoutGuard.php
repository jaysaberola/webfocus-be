<?php

namespace App\Support;

use App\Models\SalesTransaction;
use App\Models\User;
use Illuminate\Support\Collection;

class PendingCheckoutGuard
{
    /**
     * Unpaid checkout invoices that can still be completed through Paynamics.
     *
     * @return Collection<int, SalesTransaction>
     */
    public function unpaidCheckouts(User $customer): Collection
    {
        return SalesTransaction::query()
            ->where('customer_id', $customer->id)
            ->where('grand_total', '>', 0)
            ->whereNotIn('payment_status', ['paid', 'completed', 'success', 'cancelled', 'canceled'])
            ->where(function ($query) {
                $query->whereNull('order_status')
                    ->orWhereNotIn('order_status', ['cancelled', 'canceled']);
            })
            ->with('items')
            ->orderBy('id')
            ->get()
            ->reject(fn (SalesTransaction $row) => WebDesignQuotation::isPendingQuotation($row))
            ->values();
    }

    /**
     * @param  array<int, array<string, mixed>>  $checkoutItems
     */
    public function reusablePending(User $customer, array $checkoutItems, float $grandTotal): ?SalesTransaction
    {
        $wanted = $this->fingerprint($checkoutItems, $grandTotal);

        return $this->unpaidCheckouts($customer)->first(
            fn (SalesTransaction $row) => $this->transactionFingerprint($row) === $wanted
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $checkoutItems
     */
    public function overlappingPending(
        User $customer,
        array $checkoutItems,
        ?SalesTransaction $except = null
    ): ?SalesTransaction {
        $wantedCategories = $this->categoriesFromItems($checkoutItems);
        if ($wantedCategories === []) {
            return null;
        }

        return $this->unpaidCheckouts($customer)->first(function (SalesTransaction $row) use ($wantedCategories, $except) {
            if ($except && (int) $row->id === (int) $except->id) {
                return false;
            }

            $pendingCategories = $this->categoriesFromItems($this->transactionItemPayload($row));

            return count(array_intersect($wantedCategories, $pendingCategories)) > 0;
        });
    }

    public function collapseExactDuplicates(User $customer, SalesTransaction $keep): void
    {
        $keepFingerprint = $this->transactionFingerprint($keep);

        $this->unpaidCheckouts($customer)
            ->filter(function (SalesTransaction $row) use ($keep, $keepFingerprint) {
                return (int) $row->id !== (int) $keep->id
                    && $this->transactionFingerprint($row) === $keepFingerprint;
            })
            ->each(function (SalesTransaction $row) use ($keep) {
                $notes = trim((string) $row->notes);
                $line = 'Cancelled as a duplicate of pending invoice '.self::invoiceId($keep).'.';
                $row->update([
                    'payment_status' => 'cancelled',
                    'order_status' => 'cancelled',
                    'notes' => $notes === '' ? $line : $notes."\n".$line,
                ]);
            });
    }

    public function collapseAllDuplicates(User $customer): void
    {
        $this->unpaidCheckouts($customer)
            ->groupBy(fn (SalesTransaction $row) => $this->transactionFingerprint($row))
            ->each(function (Collection $group) use ($customer) {
                $keep = $group->first();
                if ($keep) {
                    $this->collapseExactDuplicates($customer, $keep);
                }
            });
    }

    public static function invoiceId(SalesTransaction $row): string
    {
        $transactionNo = trim((string) $row->transaction_no);

        return str_starts_with($transactionNo, 'INV-') ? $transactionNo : 'INV-'.$transactionNo;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function fingerprint(array $items, float $grandTotal): string
    {
        $lines = collect($items)
            ->map(function ($item) {
                $row = is_array($item) ? $item : [];
                $name = strtolower(trim((string) ($row['name'] ?? '')));
                $type = strtolower(trim((string) ($row['item_type'] ?? '')));
                $qty = number_format((float) ($row['quantity'] ?? 1), 2, '.', '');
                $price = number_format((float) ($row['price'] ?? 0), 2, '.', '');
                $category = strtolower(TransactionLabelResolver::serviceCategory($name, $type));

                return $category.'|'.$qty.'|'.$price;
            })
            ->sort()
            ->values()
            ->implode(';');

        $categories = implode('|', $this->categoriesFromItems($items));

        return $lines.'#'.$categories.'#'.number_format($grandTotal, 2, '.', '');
    }

    public function transactionFingerprint(SalesTransaction $row): string
    {
        $row->loadMissing('items');

        return $this->fingerprint(
            $this->transactionItemPayload($row),
            (float) $row->grand_total
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function transactionItemPayload(SalesTransaction $row): array
    {
        return $row->items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'name' => $item->name,
            'item_type' => $item->item_type,
            'quantity' => $item->quantity,
            'price' => $item->price,
        ])->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, string>
     */
    private function categoriesFromItems(array $items): array
    {
        return collect($items)
            ->map(function ($item) {
                $row = is_array($item) ? $item : [];

                return strtolower(TransactionLabelResolver::serviceCategory(
                    (string) ($row['name'] ?? ''),
                    (string) ($row['item_type'] ?? '')
                ));
            })
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
