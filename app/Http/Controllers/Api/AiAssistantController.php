<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Services\AiAssistantService;
use App\Http\Controllers\Controller;

class AiAssistantController extends Controller
{
    public function generate(Request $request, AiAssistantService $service)
    {
        $request->validate([
            'prompt' => 'required|string',
            'content' => 'nullable|string',
        ]);

        $html = $service->generate(
            $request->prompt,
            $request->content ?? ''
        );

        return response()->json([
            'html' => $html,
        ]);
    }
}
