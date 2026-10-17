<?php

namespace App\Support;

use App\Models\Company;
use App\Support\Licensing\InstalledLicence;

/**
 * The buyer's brand on a standalone copy (docs/STANDALONE_EDITION_PLAN.md §15): the business's
 * colour (`companies.color`), square logo (`logo`), wide logo (`logo_wide`) and tagline (`slogan`).
 *
 * Standalone only. On the SaaS every screen, email and page keeps Budget Pro's own look, so every
 * constructor below hands the SaaS an empty brand. Everything is opt-in: a business that has set
 * nothing sees Budget Pro's colours with its own name; one that has set a colour sees its own
 * buttons, links and menu.
 */
final class ShopBrand
{
    /** @var array<string, string|null> URL per stored path, null when the file is not there */
    private array $urls = [];

    private ?bool $square = null;

    public function __construct(private readonly ?Company $company)
    {
    }

    /** The signed-in person's business (standalone), else none. */
    public static function current(): self
    {
        return self::forUser(auth()->user());
    }

    /**
     * Whose sign-in page this is: a standalone copy belongs to one business, so its sign-in page is
     * theirs; the SaaS sign-in is shared by every shop and stays Budget Pro's. Once per web request
     * (the layout, the page and an error view all ask), never across a long-running worker.
     */
    public static function forSignIn(): self
    {
        if (! Edition::isStandalone()) {
            return new self(null);
        }
        $request = app()->runningInConsole() ? null : request();
        if ($request?->attributes->get(self::class) instanceof self) {
            return $request->attributes->get(self::class);
        }
        try {
            $brand = new self(Company::withoutGlobalScopes()->oldest('id')->first());
        } catch (\Throwable) {
            $brand = new self(null); // before installation there is no table to read; the page still draws
        }
        $request?->attributes->set(self::class, $brand);

        return $brand;
    }

    /** The brand an email or page for this person wears: their business's (standalone), else none. */
    public static function forUser(mixed $user): self
    {
        return self::forCompany(is_object($user) && isset($user->company_id) ? (int) $user->company_id : null);
    }

    public static function forCompany(Company|int|null $company): self
    {
        if (! Edition::isStandalone()) {
            return new self(null);
        }
        if (is_int($company)) {
            $company = rescue(fn () => Company::withoutGlobalScopes()->find($company), null, false);
        }

        return $company instanceof Company ? new self($company) : self::forSignIn();
    }

    /** What to call the system in words: the business on standalone, "Budget Pro" on the SaaS. */
    public static function productName(): string
    {
        if (! Edition::isStandalone()) {
            return 'Budget Pro';
        }

        return self::forSignIn()->name() ?? ((string) config('app.name') ?: 'Budget Pro');
    }

    /** The small "Powered by Budget Pro" line stays unless the licence is white-label. */
    public static function poweredBy(): bool
    {
        return ! (Edition::isStandalone() && InstalledLicence::current()->whiteLabel());
    }

    public function company(): ?Company
    {
        return $this->company;
    }

    /** A colour or a logo. A name alone is not a brand. */
    public function isSet(): bool
    {
        return $this->colour() !== null || $this->wideUrl() !== null || $this->logoUrl() !== null;
    }

    public function name(): ?string
    {
        return $this->clean($this->company?->name);
    }

    public function tagline(): ?string
    {
        return $this->clean($this->company?->slogan);
    }

    /** The stored colour, or null — also when it would not be readable (an imported row might not be). */
    public function colour(): ?string
    {
        $colour = BrandColour::normalise($this->company?->color);

        return $colour !== null && BrandColour::readableOnWhite($colour) ? $colour : null;
    }

    /** @return array{base:string, dark:string, soft:string}|null */
    public function palette(): ?array
    {
        $colour = $this->colour();

        return $colour === null ? null : BrandColour::palette($colour);
    }

    public function logoUrl(): ?string
    {
        return $this->url($this->clean($this->company?->logo));
    }

    /** The logo for wide places (sign-in, email header, error page): the wide logo, else a logo that is not square. */
    public function wideUrl(): ?string
    {
        return $this->url($this->clean($this->company?->getAttribute('logo_wide'))) ?? ($this->markUrl() !== null ? null : $this->logoUrl());
    }

    /** The logo for small square places (menu mark, browser tab), or null to keep Budget Pro's. */
    public function markUrl(): ?string
    {
        $url = $this->logoUrl();

        return $url !== null && $this->logoIsSquare() ? $url : null;
    }

    /**
     * The colour as CSS custom properties, or an empty string.
     *
     * @param  array<string, list<string>>  $names  shade (base, dark, deeper, soft, line) => variables
     */
    public function cssVariables(array $names): string
    {
        $colour = $this->colour();
        if ($colour === null) {
            return '';
        }
        $shades = BrandColour::palette($colour) + [
            'deeper' => BrandColour::mix($colour, '#000000', 0.36),
            'line' => BrandColour::mix($colour, '#FFFFFF', 0.7),
        ];
        $declarations = [];
        foreach ($names as $shade => $variables) {
            foreach ($variables as $variable) {
                $declarations[] = $variable.':'.$shades[$shade];
            }
        }

        return ':root{'.implode(';', $declarations).'}';
    }

    /** The web app's back-office variables (budget-pro-new resources/css/admin.css). */
    public function adminCss(): string
    {
        return $this->cssVariables(['base' => ['--br', '--ac'], 'dark' => ['--br-d'], 'soft' => ['--br-soft']]);
    }

    /** The sign-in pages' variables (budget-pro-new resources/css/auth.css). */
    public function authCss(): string
    {
        return $this->cssVariables(['base' => ['--pri', '--br'], 'dark' => ['--pri-d'], 'deeper' => ['--pri-dd'], 'soft' => ['--pri-soft'], 'line' => ['--pri-line']]);
    }

    private function logoIsSquare(): bool
    {
        if ($this->square !== null) {
            return $this->square;
        }
        $path = $this->clean($this->company?->logo);
        $size = $path !== null && ! str_contains($path, '..') ? @getimagesize(self::mediaRoot().'/'.ltrim($path, '/')) : false;

        return $this->square = is_array($size) && $size[1] > 0 && $size[0] / $size[1] >= 0.75 && $size[0] / $size[1] <= 1.34;
    }

    /** A URL only for a file that is really there — a missing one would draw an empty box. */
    private function url(?string $path): ?string
    {
        if ($path === null || str_contains($path, '..')) {
            return null;
        }
        if (! array_key_exists($path, $this->urls)) {
            $this->urls[$path] = is_file(self::mediaRoot().'/'.ltrim($path, '/'))
                ? rtrim(self::mediaUrl(), '/').'/'.ltrim($path, '/') : null;
        }

        return $this->urls[$path];
    }

    /** Uploads live in the kernel's public storage; the web app reaches it through config budgetpro.*. */
    private static function mediaRoot(): string
    {
        return rtrim((string) (config('budgetpro.media_root') ?: config('filesystems.disks.admin.root') ?: public_path('storage')), '/');
    }

    private static function mediaUrl(): string
    {
        return (string) (config('budgetpro.media_url') ?: config('filesystems.disks.admin.url') ?: url('storage'));
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
