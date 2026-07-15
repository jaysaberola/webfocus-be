<?php

namespace App\Http\Controllers\Api;

use App\Models\Option;
use Illuminate\Http\Request;
use App\Http\Resources\OptionResource;
use App\Http\Controllers\Controller;

class OptionController extends Controller
{
    // ONLY FETCH OPTIONS (no store/update here)
    public function index(Request $request)
    {
        $options = Option::query()
            ->when($request->type, fn ($q) =>
                $q->where('type', $request->type)
            )
            ->when($request->field_type, fn ($q) =>
                $q->where('field_type', $request->field_type)
            )
            ->orderBy('name')
            ->get();

        return OptionResource::collection($options);
    }
}
