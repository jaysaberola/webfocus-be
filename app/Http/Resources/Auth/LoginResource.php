<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoginResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fname' => $this->fname,
            'mname' => $this->mname,
            'lname' => $this->lname,
            'email' => $this->email,
            'token' => $this->token,
            'role' => $this->getRoleNames()->first(),
            'roles' => $this->getRoleNames()->values()->all(),
            'role_description' => $this->roles->first()?->description,
            'permissions' => $this->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }
}