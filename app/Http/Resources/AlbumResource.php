<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AlbumResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'total_images' => $this->banners_count ?? 0,
            'updated_at'   => $this->updated_at ? $this->updated_at->format('M d, Y g:i A') : null,
            'deleted_at'   => $this->deleted_at,
            'is_deleted'   => ! is_null($this->deleted_at),
            'visibility'   => $this->deleted_at ? 'Deleted' : null,
        ];
    }
}
