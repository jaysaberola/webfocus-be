<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Checkout used to hardcode lname = "User" when saving a billing address.
        DB::table('users')
            ->whereRaw('LOWER(TRIM(lname)) IN (?, ?)', ['customer', 'user'])
            ->update(['lname' => '']);

        $rows = DB::table('sales_transactions')
            ->whereNotNull('customer_name')
            ->where('customer_name', '!=', '')
            ->where(function ($q) {
                $q->where('customer_name', 'like', '% Customer')
                    ->orWhere('customer_name', 'like', '% User')
                    ->orWhere('customer_name', 'like', '% customer')
                    ->orWhere('customer_name', 'like', '% user')
                    ->orWhereRaw('LOWER(TRIM(customer_name)) IN (?, ?)', ['customer', 'user']);
            })
            ->get(['id', 'customer_name']);

        foreach ($rows as $row) {
            $cleaned = trim(preg_replace('/\s+(Customer|User)$/i', '', (string) $row->customer_name) ?? '');
            if (preg_match('/^(customer|user)$/i', $cleaned)) {
                $cleaned = '';
            }
            if ($cleaned !== (string) $row->customer_name) {
                DB::table('sales_transactions')
                    ->where('id', $row->id)
                    ->update(['customer_name' => $cleaned !== '' ? $cleaned : null]);
            }
        }
    }

    public function down(): void
    {
        // Irreversible data cleanup.
    }
};
