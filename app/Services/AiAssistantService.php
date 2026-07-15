<?php

namespace App\Services;

use OpenAI;

class AiAssistantService
{
    public function generate(string $prompt, string $existingHtml = ''): string
    {
        $client = OpenAI::client(config('services.openai.key'));

        $systemPrompt = <<<SYS
You are an expert web designer.

Rules:
- Output ONLY valid HTML
- Use INLINE CSS only
- Do NOT wrap in markdown
- Do NOT explain anything
- If existing HTML is provided, MODIFY or ENHANCE it based on the prompt
- If empty, CREATE a full page section
- Keep content clean and professional
SYS;

        $response = $client->chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Existing HTML:\n{$existingHtml}"],
                ['role' => 'user', 'content' => "User request:\n{$prompt}"],
            ],
            'temperature' => 0.4,
        ]);

        return trim($response->choices[0]->message->content);
    }
}
