<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Signup used to hardcode lname = "Customer" (or "User"). Clear those placeholders.
        DB::table('users')
            ->whereRaw('LOWER(TRIM(lname)) IN (?, ?)', ['customer', 'user'])
            ->update(['lname' => '']);

        // Contact person often mirrored "fname Customer" — strip the trailing placeholder word.
        $rows = DB::table('users')
            ->whereNotNull('contact_person')
            ->where('contact_person', '!=', '')
            ->where(function ($q) {
                $q->where('contact_person', 'like', '% Customer')
                    ->orWhere('contact_person', 'like', '% User')
                    ->orWhere('contact_person', 'like', '% customer')
                    ->orWhere('contact_person', 'like', '% user');
            })
            ->get(['id', 'contact_person', 'fname']);

        foreach ($rows as $row) {
            $cleaned = trim(preg_replace('/\s+(Customer|User)$/i', '', (string) $row->contact_person) ?? '');
            if ($cleaned === '') {
                $cleaned = trim((string) ($row->fname ?? ''));
            }
            if ($cleaned !== (string) $row->contact_person) {
                DB::table('users')->where('id', $row->id)->update(['contact_person' => $cleaned ?: null]);
            }
        }
    }

    public function down(): void
    {
        // Irreversible data cleanup.
    }
};
