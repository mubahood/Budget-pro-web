<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // A second account's owner has no contact of their own (SiblingAccounts): they are the same person, reached the same way.
        $contact = \App\Services\Onboarding\SiblingAccounts::isSiblingUsername((string) $this->username) ? \App\Services\Onboarding\SiblingAccounts::signInOf($this->resource) : $this->resource;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $contact->email,
            'username' => $this->username,
            'phone_number' => $contact->phone_number,
            'phone_e164' => $contact->phone_e164,
            'phone_verified' => $this->phone_verified_at !== null,
            'email_verified' => $this->email_verified_at !== null,
            'locale' => $this->locale ?? 'en',
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
