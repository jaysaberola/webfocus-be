<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use OwenIt\Auditing\Models\Audit;

class AuditTrailController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->get('search');

        $audits = Audit::with('user')
            ->when($search, function ($q) use ($search) {
                $q->where(function ($query) use ($search) {
                    $query->where('event', 'like', "%{$search}%")
                        ->orWhere('auditable_type', 'like', "%{$search}%")
                        ->orWhere('auditable_id', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhere('url', 'like', "%{$search}%")
                        ->orWhere('user_agent', 'like', "%{$search}%")
                        ->orWhere('tags', 'like', "%{$search}%")
                        ->orWhere('old_values', 'like', "%{$search}%")
                        ->orWhere('new_values', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('fname', 'like', "%{$search}%")
                                        ->orWhere('lname', 'like', "%{$search}%")
                                        ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 10));

        return response()->json($audits);
    }
}
