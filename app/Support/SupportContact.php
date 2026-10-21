<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Who to ask for help, in one place (docs/roadmap/06-selling-seo-and-whatsapp.md §4).
 *
 * Every page, email, receipt and API answer that says "WhatsApp us" reads this class and nothing
 * else; the number is never typed into a view. On the SaaS the values come from config/support.php
 * (the environment, with defaults): there is no platform settings table.
 *
 * A standalone copy may be supported by somebody other than us, so {@see forCompany()} answers in
 * this order: the licence's partner (no licence carries a support block yet, so that layer is not
 * read), then the business's own phone and email (Settings › Business profile), then ours.
 *
 * A prefilled message never carries a customer's name, an amount or a phone number: only a page or
 * screen name, the app, the shop's name, the person's role, an error reference, a version or a
 * licence id (lang/en/support.php). Values that look like a phone number are dropped.
 *
 * Nothing here may fail: error pages ask too, and the database may be what failed.
 */
final class SupportContact
{
    public const WHO_PLATFORM = 'platform';

    public const WHO_PARTNER = 'partner';

    public const WHO_OWN = 'own';

    /** The context a link falls back to when it names one that does not exist. */
    public const FALLBACK = 'site.general';

    /** Where a tracked click came from (support_clicks.app): the surfaces, then the phone app flavors. */
    public const APPS = ['site', 'admin', 'classic', 'mail', 'api', 'budget_pro', 'kuku_tally', 'shelfie', 'pledge_and_budget', 'akiba_ledger'];

    /** An error reference: E + the error's fingerprint, or R + a request's own id (8 hex characters). */
    public const REFERENCE_PATTERN = '/^[ER][0-9A-F]{8}$/';

    /** What a secret in a page path becomes before it is stored (support_clicks.page) or put in a link. */
    public const MASKED_TOKEN = ':token';

    /**
     * Paths whose next segments are secrets, with how many: an invite, a password reset, a sign-in link from the
     * app, a public receipt, a licence claim, an unsubscribe link (customer and token). Matched anywhere in the
     * path, so a copy hosted in a sub-folder (/budget-pro-new/invite/…) is covered too.
     */
    private const SECRET_AFTER = ['invite' => 1, 'reset-password' => 1, 'web-link' => 1, 'r' => 1, 'my-licence/claim' => 1, 'stop' => 2];

    /** @param array{whatsapp: string, phone: string, email: string, hours: string} $values */
    private function __construct(private readonly array $values, private readonly string $who, private readonly string $name)
    {
    }

    /** The platform's own contact (us), from config/support.php. */
    public static function platform(): self
    {
        $read = static function (string $key): string {
            try {
                return trim((string) config('support.'.$key));
            } catch (Throwable) {
                return '';
            }
        };

        return new self([
            'whatsapp' => $read('whatsapp'),
            'phone' => $read('phone'),
            'email' => $read('email'),
            'hours' => $read('hours'),
        ], self::WHO_PLATFORM, 'Budget Pro');
    }

    /**
     * The contact for this business's copy. Only a standalone copy has anyone but us to ask: its own
     * phone and email when the business has set them (the person who runs the copy answers first),
     * else ours. On the SaaS, always ours.
     */
    public static function forCompany(?Company $company = null): self
    {
        $platform = self::platform();
        try {
            if (! Edition::isStandalone()) {
                return $platform;
            }
            $company ??= ShopBrand::forSignIn()->company();
            if ($company === null) {
                return $platform;
            }
            $own = array_filter([
                'phone' => trim((string) $company->phone_number),
                'email' => trim((string) $company->email),
            ], fn (string $v) => $v !== '');

            return $own === [] ? $platform : $platform->overlaid($own, self::WHO_OWN, trim((string) $company->name) ?: 'Your office');
        } catch (Throwable) {
            return $platform;
        }
    }

    /** The product name the prefilled messages greet (Akiba Ledger stands alone: AK-68). */
    private string $product = 'Budget Pro';

    /**
     * Our contact as Akiba Ledger: the same line, but every prefilled message greets "Akiba Ledger" and names no other
     * product (the owner's rule for anything a SACCO's staff or members receive).
     */
    public static function forSacco(): self
    {
        $c = self::platform();
        $c->product = 'Akiba Ledger';

        return $c;
    }

    /** Whether a company keeps SACCO books (its mail, footers and receipts speak as Akiba Ledger). */
    public static function isSacco(Company|int|null $company): bool
    {
        try {
            $company = is_int($company) ? Company::withoutGlobalScopes()->find($company) : $company;

            return $company !== null && in_array('sacco', $company->modules(), true);
        } catch (Throwable) {
            return false;
        }
    }

    /** The contact for whoever is looking now: a standalone copy's own, else ours. */
    public static function current(): self
    {
        return self::forCompany();
    }

    /** @param array<string, string> $over */
    private function overlaid(array $over, string $who, string $name): self
    {
        $merged = $this->values;
        foreach (['whatsapp', 'phone', 'email', 'hours'] as $key) {
            if (isset($over[$key])) {
                $merged[$key] = $over[$key];
            }
        }
        // Somebody else answers: our WhatsApp and our hours must not stay behind under their name.
        if (! isset($over['whatsapp'])) {
            $merged['whatsapp'] = '';
        }
        if (! isset($over['hours'])) {
            $merged['hours'] = '';
        }

        return new self($merged, $who, $name);
    }

    /** The digits for a link, country code first. Empty when there is no WhatsApp line. */
    public function whatsappDigits(): string
    {
        return preg_replace('/\D+/', '', $this->values['whatsapp']) ?? '';
    }

    /** The number as people read it: +256 700 000 001. */
    public function whatsappDisplay(): string
    {
        return self::formatNumber($this->whatsappDigits());
    }

    /** International digits as people read them; empty for none. */
    public static function formatNumber(string $digits): string
    {
        $digits = preg_replace('/\D+/', '', $digits) ?? '';
        if ($digits === '') {
            return '';
        }
        // Uganda and the rest of Africa (+2xx): a three-digit country code and a nine-digit number.
        if (preg_match('/^(2\d{2})(\d{3})(\d{3})(\d{3})$/', $digits, $m)) {
            return "+{$m[1]} {$m[2]} {$m[3]} {$m[4]}";
        }

        return '+'.$digits;
    }

    public function hasWhatsapp(): bool
    {
        return $this->whatsappDigits() !== '';
    }

    public function phone(): string
    {
        return $this->values['phone'];
    }

    /** The phone as a `tel:` target: digits and a leading plus. */
    public function phoneLink(): string
    {
        $phone = preg_replace('/(?!^\+)[^\d]/', '', $this->phone()) ?? '';

        return $phone === '' || $phone === '+' ? '' : 'tel:'.$phone;
    }

    /** Whether the phone is the WhatsApp number (then one line says both). */
    public function phoneIsWhatsapp(): bool
    {
        $phone = preg_replace('/\D+/', '', $this->phone()) ?? '';

        return $phone !== '' && $this->hasWhatsapp() && $phone === $this->whatsappDigits();
    }

    public function email(): string
    {
        return $this->values['email'];
    }

    public function emailLink(): string
    {
        return $this->email() === '' ? '' : 'mailto:'.$this->email();
    }

    public function hours(): string
    {
        return $this->values['hours'];
    }

    /** The public status page, when there is one (ours only). */
    public function statusUrl(): string
    {
        try {
            return $this->who === self::WHO_PLATFORM ? trim((string) config('support.status_url')) : '';
        } catch (Throwable) {
            return '';
        }
    }

    /** platform, partner or own: who answers. */
    public function who(): string
    {
        return $this->who;
    }

    public function isPlatform(): bool
    {
        return $this->who === self::WHO_PLATFORM;
    }

    /** Who answers, in words: "Budget Pro", or the business (own) or partner that supports this copy. */
    public function name(): string
    {
        return $this->name;
    }

    /** Anything at all to offer: a WhatsApp line, a phone or an email. */
    public function reachable(): bool
    {
        return $this->hasWhatsapp() || $this->phone() !== '' || $this->email() !== '';
    }

    /**
     * The message for a context, filled from $vars: lang/en/support.php's rules (a [part] stays only
     * when all its placeholders have a value; an unknown placeholder becomes nothing, never ":name").
     * An unknown context gets the general message.
     *
     * @param  array<string, scalar|null>  $vars
     */
    public function message(string $context, array $vars = []): string
    {
        $templates = self::templates();

        $text = self::fill($templates[$context] ?? $templates[self::FALLBACK] ?? '', $vars);

        return $this->product === 'Budget Pro' ? $text : str_replace('Budget Pro', $this->product, $text);
    }

    /**
     * A template filled with values. Values are trimmed to one line, and anything that looks like a
     * phone number is dropped from them (a shop named after its number must not put it in a message).
     *
     * @param  array<string, scalar|null>  $vars
     */
    public static function fill(string $template, array $vars): string
    {
        $value = static function (string $key) use ($vars): string {
            $v = trim((string) preg_replace('/\s+/', ' ', (string) ($vars[$key] ?? '')));
            if (! in_array($key, ['ref', 'licence', 'version', 'report'], true)) {
                $v = trim((string) preg_replace('/\+?\d[\d\s().-]{6,}\d/', '', $v));
            }

            return mb_substr($v, 0, $key === 'report' ? 1500 : 120);
        };

        $text = (string) preg_replace_callback('/\[([^\[\]]*)\]/', function (array $m) use ($value): string {
            preg_match_all('/:([a-z_]+)/', $m[1], $names);
            foreach ($names[1] as $name) {
                if ($value($name) === '') {
                    return '';
                }
            }

            return $m[1];
        }, $template);
        $text = (string) preg_replace_callback('/:([a-z_]+)/', fn (array $m) => $value($m[1]), $text);
        $text = (string) preg_replace('/\s+/', ' ', $text);
        $text = (string) preg_replace('/\s+([.,):])/', '$1', $text);
        $text = (string) preg_replace('/\(\s+/', '(', $text);

        return trim($text);
    }

    /**
     * `https://wa.me/<digits>?text=…` for a named context; empty when there is no WhatsApp line.
     *
     * @param  array<string, scalar|null>  $vars
     */
    public function waLink(string $context, array $vars = []): string
    {
        if (! $this->hasWhatsapp()) {
            return '';
        }

        return 'https://wa.me/'.$this->whatsappDigits().'?text='.rawurlencode($this->message($context, $vars));
    }

    /**
     * The tracked link (`/go/whatsapp`, which records the click and redirects to wa.me) when the web
     * app can answer it, else the plain wa.me link. Empty when there is no WhatsApp line.
     *
     * The tracked route fills the shop, role and screen itself from the session and the page, so it
     * only carries the context, the page path, where the link was, an error reference and a plan
     * limit's name. A link with other values from a place the web app cannot see (a classic page, an
     * email) stays a plain wa.me link, so nothing it says is lost.
     *
     * @param  array<string, scalar|null>  $vars
     */
    public function goLink(string $context, array $vars = [], ?string $page = null, ?string $app = null): string
    {
        if (! $this->hasWhatsapp()) {
            return '';
        }
        $params = array_filter([
            'c' => $context,
            'p' => $page === null ? null : self::pagePath($page),
            'a' => in_array($app, self::APPS, true) ? $app : null,
            'r' => is_string($vars['ref'] ?? null) && preg_match(self::REFERENCE_PATTERN, $vars['ref']) ? $vars['ref'] : null,
            'l' => is_string($vars['limit'] ?? null) && preg_match('/^[a-z_ ]{2,40}$/', $vars['limit']) ? $vars['limit'] : null,
        ], fn ($v) => $v !== null && $v !== '');

        try {
            if (Route::has('support.whatsapp')) {
                return route('support.whatsapp', $params);
            }
            $portal = rtrim((string) config('saas.new_ui_url'), '/');
            if ($portal !== '' && array_diff_key($vars, ['ref' => 1, 'limit' => 1]) === []) {
                return $portal.'/go/whatsapp?'.http_build_query($params);
            }
        } catch (Throwable) {
            // No router or no config: the plain link still works.
        }

        return $this->waLink($context, $vars);
    }

    /**
     * A page as stored and passed on: its path only (no host, no query), with every secret in it masked
     * (maskTokens), at most 191 characters. Running it again on its own result changes nothing.
     */
    public static function pagePath(string $page): string
    {
        $path = (string) (parse_url($page, PHP_URL_PATH) ?? '');
        $path = '/'.ltrim(preg_replace('#[^A-Za-z0-9/_.:\-]#', '', $path) ?? '', '/');

        return mb_substr(self::maskTokens($path), 0, 191);
    }

    /**
     * A path with its secrets replaced by MASKED_TOKEN, so a tap on "WhatsApp us" from an invite or a reset page
     * never stores or forwards a live token: the segments after the SECRET_AFTER paths, and any segment that
     * looks like a token (16 or more letters, digits, - or _). A plain lower-case slug such as
     * reorder-suggestions is a route's name, not a token (random tokens carry digits or capitals), so it stays.
     */
    public static function maskTokens(string $path): string
    {
        $out = [];
        $secret = 0;
        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                $out[] = $segment;

                continue;
            }
            if ($secret > 0 || (preg_match('/^[A-Za-z0-9_-]{16,}$/', $segment) === 1 && preg_match('/^[a-z]+(?:-[a-z]+)*$/', $segment) !== 1)) {
                $out[] = self::MASKED_TOKEN;
                $secret = max(0, $secret - 1);

                continue;
            }
            $out[] = $segment;
            $sofar = '/'.strtolower(trim(implode('/', $out), '/'));
            foreach (self::SECRET_AFTER as $prefix => $count) {
                if (str_ends_with($sofar, '/'.$prefix)) {
                    $secret = $count;
                }
            }
        }

        return implode('/', $out);
    }

    /**
     * Two plain-text lines for the foot of a platform email: who to ask and how, with the hours, then
     * the WhatsApp link. Empty when there is nothing to offer.
     *
     * @param  array<string, scalar|null>  $vars
     */
    public function mailLine(string $context = 'mail.general', array $vars = []): string
    {
        $parts = $this->mailParts($context, $vars);
        if ($parts['text'] === '') {
            return '';
        }

        return $parts['text'].($parts['link'] !== '' ? "\n".$parts['link'] : '');
    }

    /**
     * The same footer as words and a link, for an HTML email.
     *
     * @param  array<string, scalar|null>  $vars
     * @return array{text: string, link: string}
     */
    public function mailParts(string $context = 'mail.general', array $vars = []): array
    {
        if (! $this->isPlatform()) {
            // The business (or partner) that runs this copy answers: its phone and email, no WhatsApp of ours.
            $how = array_filter([$this->phone() !== '' ? 'on '.$this->phone() : null, $this->email() !== '' ? 'at '.$this->email() : null]);
            if ($how === []) {
                return ['text' => '', 'link' => ''];
            }
            $line = 'Need a hand? Contact '.$this->name().' '.implode(' or ', $how).'.';
        } else {
            $parts = [];
            if ($this->phoneIsWhatsapp()) {
                $parts[] = 'WhatsApp or call us on '.$this->whatsappDisplay();
            } else {
                if ($this->hasWhatsapp()) {
                    $parts[] = 'WhatsApp us on '.$this->whatsappDisplay();
                }
                if ($this->phone() !== '') {
                    $parts[] = ($parts === [] ? 'call us on ' : 'call ').$this->phone();
                }
            }
            if ($this->email() !== '') {
                $parts[] = ($parts === [] ? 'email us at ' : 'email ').$this->email();
            }
            if ($parts === []) {
                return ['text' => '', 'link' => ''];
            }
            $last = array_pop($parts);
            $line = 'Need a hand? '.ucfirst($parts === [] ? $last : implode(', ', $parts).' or '.$last).'.';
        }
        if ($this->hours() !== '') {
            $line .= ' '.rtrim($this->hours(), '.').'.';
        }

        // Akiba Ledger's mail links straight to WhatsApp with its own greeting (the tracked link fills ours).
        return ['text' => $line, 'link' => $this->product === 'Budget Pro' ? $this->goLink($context, $vars, null, 'mail') : $this->waLink($context, $vars)];
    }

    /** Whether this business's receipts and invoices carry the small "Powered by Budget Pro" line. */
    public static function receiptLineOn(Company $company): bool
    {
        try {
            if (! ShopBrand::poweredBy()) {
                return false; // a white-label licence never names us
            }
            if (self::isSacco($company)) {
                return false; // Akiba Ledger names no other product (AK-68)
            }
            $chosen = $company->getAttribute('receipt_support_line');
            if ($chosen === null) {
                return (bool) config('support.receipt_line_default.'.Edition::name(), Edition::isSaas());
            }

            return (bool) $chosen;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * "Powered by Budget Pro · WhatsApp +256 …" for a receipt or an invoice, or null when the
     * business has it off. The number is always ours: the line is about who made the system.
     */
    public static function receiptLine(Company $company): ?string
    {
        return self::receiptLineOn($company) ? self::receiptLineText() : null;
    }

    /** The line's words, whatever a business chose (Settings › Receipts previews it). */
    public static function receiptLineText(): string
    {
        $us = self::platform();
        $reach = $us->hasWhatsapp() ? 'WhatsApp '.$us->whatsappDisplay() : $us->phone();

        return 'Powered by Budget Pro'.($reach !== '' ? ' · '.$reach : '');
    }

    /**
     * The reference a person reads out to support. An unexpected error (reported, counted on System
     * health) is E + the start of its ErrorTracker fingerprint; anything else is R + this request's own
     * id (the request id in the logs when there is one), the same for the whole request.
     */
    public static function reference(?Throwable $e = null): string
    {
        try {
            $error = $e instanceof HttpExceptionInterface
                ? ($e->getStatusCode() >= 500 && $e->getPrevious() !== null && ! $e->getPrevious() instanceof HttpExceptionInterface ? $e->getPrevious() : null)
                : $e;
            if ($error !== null) {
                return 'E'.strtoupper(substr(ErrorTracker::fingerprint($error), 0, 8));
            }

            $request = app()->bound('request') ? request() : null;
            $ref = $request?->attributes->get('support_ref');
            if (is_string($ref) && $ref !== '') {
                return $ref;
            }
            $id = strtoupper((string) preg_replace('/[^A-Fa-f0-9]/', '', (string) $request?->attributes->get('request_id')));
            $ref = 'R'.(strlen($id) >= 8 ? substr($id, 0, 8) : strtoupper(bin2hex(random_bytes(4))));
            $request?->attributes->set('support_ref', $ref);

            return $ref;
        } catch (Throwable) {
            return 'R'.strtoupper(bin2hex(random_bytes(4)));
        }
    }

    /**
     * The prefilled messages, with their placeholders, keyed by context.
     *
     * @return array<string, string>
     */
    public static function templates(): array
    {
        static $loaded = null;
        if ($loaded !== null) {
            return $loaded;
        }
        try {
            self::registerLang();
            $lines = Lang::getLoader()->load('en', 'support', 'bp');
        } catch (Throwable) {
            $lines = [];
        }
        if ($lines === [] && is_file($file = self::langPath().'/en/support.php')) {
            $lines = (array) require $file;
        }
        $out = [];
        foreach ($lines as $key => $text) {
            if (is_string($key) && is_string($text) && str_contains($key, '.')) {
                $out[$key] = $text;
            }
        }

        return $loaded = $out;
    }

    /** The `bp` lang namespace (this kernel's lang folder), for `bp::support.*` in both apps. */
    public static function registerLang(): void
    {
        $loader = Lang::getLoader();
        if (! array_key_exists('bp', $loader->namespaces())) {
            $loader->addNamespace('bp', self::langPath());
        }
    }

    public static function langPath(): string
    {
        return dirname(__DIR__, 2).'/lang';
    }

    /**
     * What the API gives the phone apps: the contact, who answers, and the prefilled messages with
     * their placeholders, so the app fills them itself (offline too, from its last copy).
     *
     * @return array{whatsapp: string|null, whatsapp_display: string|null, phone: string|null, email: string|null, hours: string|null, who: string, name: string, status_url: string|null, templates: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'whatsapp' => $this->whatsappDigits() ?: null,
            'whatsapp_display' => $this->whatsappDisplay() ?: null,
            'phone' => $this->phone() ?: null,
            'email' => $this->email() ?: null,
            'hours' => $this->hours() ?: null,
            'who' => $this->who,
            'name' => $this->name,
            'status_url' => $this->statusUrl() ?: null,
            'templates' => self::templates(),
        ];
    }
}
