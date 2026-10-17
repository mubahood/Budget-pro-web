<?php

namespace App\Mail;

use App\Support\ShopBrand;
use Illuminate\Mail\Mailable;

/**
 * An email from Messenger's mail channel on a standalone copy (docs/STANDALONE_EDITION_PLAN.md §15):
 * the same plain text as on the SaaS, wrapped in the business's header (wide logo or name, its
 * colour) and footer (its contact details, who to ask for help when that is not the business itself
 * (App\Support\SupportContact), and "Powered by Budget Pro" unless white-label).
 */
class ShopMessage extends Mailable
{
    /** @param array{text: string, link: string} $support SupportContact::mailParts(), or empty parts */
    public function __construct(public string $text, string $subject, public ShopBrand $brand, public array $support = ['text' => '', 'link' => ''])
    {
        $this->subject($subject);
    }

    public function build(): static
    {
        $company = $this->brand->company();

        return $this->view('messaging.mail')->text('messaging.mail-text')->with([
            'body' => $this->text,
            'name' => $this->brand->name() ?? ShopBrand::productName(),
            'wide' => $this->brand->wideUrl(),
            'colour' => $this->brand->colour() ?? '#0F7B4F',
            'contact' => array_values(array_filter([$company?->address, $company?->phone_number, $company?->email])),
            'poweredBy' => ShopBrand::poweredBy(),
            'supportText' => (string) ($this->support['text'] ?? ''),
            'supportLink' => (string) ($this->support['link'] ?? ''),
        ]);
    }
}
