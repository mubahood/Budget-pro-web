<?php

namespace App\Services\Messaging;

use App\Mail\ShopMessage;
use App\Support\Edition;
use App\Support\ShopBrand;
use Illuminate\Support\Facades\Mail;

/**
 * Email through Laravel's mailer. On the SaaS a plain-text message, as always; on a standalone copy
 * the same text in the business's own header and footer, under its name (App\Mail\ShopMessage).
 */
class MailChannel implements MessagingChannel
{
    public function name(): string
    {
        return 'mail';
    }

    public function send(string $to, string $text, array $options = []): array
    {
        try {
            if (Edition::isStandalone()) {
                $brand = ShopBrand::forCompany(isset($options['company_id']) ? (int) $options['company_id'] : null);
                Mail::to($to)->send(new ShopMessage($text, (string) ($options['subject'] ?? ShopBrand::productName()), $brand));

                return ['ok' => true];
            }
            Mail::raw($text, function ($m) use ($to, $options) {
                $m->to($to)->subject($options['subject'] ?? config('app.name'));
            });

            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
