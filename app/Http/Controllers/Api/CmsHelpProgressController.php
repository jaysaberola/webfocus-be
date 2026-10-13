<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CmsGuideProgress;
use Illuminate\Http\Request;

class CmsHelpProgressController extends Controller
{
    public function index(Request $request)
    {
        $rows = CmsGuideProgress::query()
            ->where('user_id', $request->user()->id)
            ->get()
            ->map(fn (CmsGuideProgress $row) => $this->payload($row))
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function upsert(Request $request, string $guideId)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:not_started,in_progress,completed'],
            'step_index' => ['nullable', 'integer', 'min:0', 'max:200'],
        ]);

        $guideId = $this->normalizeGuideId($guideId);
        $status = $validated['status'] === 'not_started' ? 'in_progress' : $validated['status'];
        $stepIndex = (int) ($validated['step_index'] ?? 0);

        $row = CmsGuideProgress::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'guide_id' => $guideId,
            ],
            [
                'status' => $status,
                'step_index' => $status === 'completed' ? $stepIndex : $stepIndex,
                'completed_at' => $status === 'completed' ? now() : null,
            ]
        );

        return response()->json(['data' => $this->payload($row)]);
    }

    private function normalizeGuideId(string $guideId): string
    {
        $guideId = strtolower(trim($guideId));
        abort_unless(preg_match('/^[a-z0-9-]{2,80}$/', $guideId) === 1, 422, 'Invalid guide.');

        return $guideId;
    }

    private function payload(CmsGuideProgress $row): array
    {
        return [
            'guideId' => $row->guide_id,
            'status' => $row->status,
            'stepIndex' => (int) $row->step_index,
            'completedAt' => optional($row->completed_at)?->toIso8601String(),
        ];
    }
}
