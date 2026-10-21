<?php

namespace App\Services\Messaging;

use App\Mail\ShopMessage;
use App\Support\Edition;
use App\Support\ShopBrand;
use App\Support\SupportContact;
use Illuminate\Support\Facades\Mail;

/**
 * Email through Laravel's mailer. On the SaaS a plain-text message, as always; on a standalone copy
 * the same text in the business's own header and footer, under its name (App\Mail\ShopMessage).
 *
 * Every email ends with who to ask for help (SupportContact, docs/roadmap/06 §4): on the SaaS ours,
 * on a standalone copy the copy's own. Options: `support_context` (which prefilled WhatsApp message,
 * default mail.general), `support_vars` (its values: a shop's name, never a customer's) and
 * `support_footer` false for mail to our own inbox.
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
            $context = (string) ($options['support_context'] ?? 'mail.general');
            $vars = is_array($options['support_vars'] ?? null) ? $options['support_vars'] : [];
            $footer = ($options['support_footer'] ?? true) !== false;
            if (Edition::isStandalone()) {
                $brand = ShopBrand::forCompany(isset($options['company_id']) ? (int) $options['company_id'] : null);
                $support = $footer ? SupportContact::forCompany($brand->company()) : null;
                // The business's own contact is already in the footer: say it once.
                $parts = $support !== null && $support->who() !== SupportContact::WHO_OWN ? $support->mailParts($context, $vars) : ['text' => '', 'link' => ''];
                Mail::to($to)->send(new ShopMessage($text, (string) ($options['subject'] ?? ShopBrand::productName()), $brand, $parts));

                return ['ok' => true];
            }
            // Akiba Ledger stands alone: a SACCO's mail carries neither the family's footer nor its name.
            // (its own footer greets "Akiba Ledger", AK-68).
            $sacco = ! empty($options['akiba']) || (isset($options['company_id']) && SupportContact::isSacco((int) $options['company_id']));
            $line = $footer ? ($sacco ? SupportContact::forSacco() : SupportContact::platform())->mailLine($context, $vars) : '';
            Mail::raw($line === '' ? $text : rtrim($text)."\n\n--\n".$line, function ($m) use ($to, $options, $sacco) {
                $m->to($to)->subject($options['subject'] ?? ($sacco ? 'Akiba Ledger' : config('app.name')));
                if ($sacco) {
                    $m->from((string) config('mail.from.address'), 'Akiba Ledger');
                }
            });

            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
