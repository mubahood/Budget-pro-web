<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'owner_id' => $this->owner_id,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'phone_number_2' => $this->phone_number_2,
            'address' => $this->address,
            'logo' => $this->logo,
            'website' => $this->website,
            'about' => $this->about,
            'slogan' => $this->slogan,
            'currency' => $this->currency,
            'country' => $this->country,
            'locale' => $this->locale,
            'business_type' => $this->business_type,
            'timezone' => $this->timezone,
            'modules' => $this->resource->modules(),
            'payment_methods' => $this->payment_methods,
            'receipt_channels' => $this->receipt_channels,
            'tax_rate' => $this->tax_rate === null ? null : (float) $this->tax_rate,
            'onboarding' => [
                'step' => $this->onboarding_state['step'] ?? 'business',
                'completed_at' => $this->onboarding_state['completed_at'] ?? null,
            ],
            'status' => $this->status,
            'license_expire' => optional($this->license_expire)->toDateString(),
            'has_active_access' => $this->hasActiveAccess(),
            'shop' => [
                'receipt_header' => $this->receipt_header,
                'receipt_footer' => $this->receipt_footer,
                'negative_stock_policy' => $this->negative_stock_policy ?? 'flag',
                'low_stock_default' => $this->low_stock_default === null ? null : (float) $this->low_stock_default,
                'require_shift' => (bool) $this->require_shift,
                'timezone' => $this->timezone,
            ],
            'settings' => [
                'worker_can_create_stock_item' => $this->settings_worker_can_create_stock_item,
                'worker_can_create_stock_record' => $this->settings_worker_can_create_stock_record,
                'worker_can_create_stock_category' => $this->settings_worker_can_create_stock_category,
                'worker_can_view_balance' => $this->settings_worker_can_view_balance,
                'worker_can_view_stats' => $this->settings_worker_can_view_stats,
            ],
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
