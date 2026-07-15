<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'title'        => $this->name,
            'label'        => $this->label ?? $this->name,
            'slug'         => $this->slug,
            'url'          => url($this->slug),
            'excerpt'      => $this->meta_description,
            'visibility'   => ucfirst($this->status),
            'lastModified' => $this->updated_at?->format('M d, Y g:i A'),
        ];
    }
}
