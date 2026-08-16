<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'username' => $this->username,
            'phone_number' => $this->phone_number,
            'phone_number_2' => $this->phone_number_2,
            'avatar' => $this->avatar,
            'address' => $this->address,
            'sex' => $this->sex,
            'status' => $this->status,
            'company_id' => $this->company_id,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
