<?php

namespace App\Services\Budget;

use App\Exceptions\BusinessRuleException;
use App\Models\BudgetProgram;
use App\Models\EventCardTemplate;
use App\Models\User;
use App\Services\Onboarding\PublicDemo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An event's invitation card designs (who may: BudgetAccess::canEditSettings, the chair or full access). A design is a
 * picture plus where and how the invitee's name goes on it (the migration says what each figure means). An image is
 * kept as uploaded. A PDF comes with a picture of the chosen page, drawn by the browser (no PDF tools on the server),
 * and is kept too, so a card downloaded as PDF stays sharp. The browser makes every card; the server only keeps designs.
 *
 * At most one design of an event is its default, the one the card maker opens with; the first design takes it, and
 * deleting the default hands it to the newest other design.
 */
final class CardTemplates
{
    public const MAX_PER_EVENT = 12;

    public const MAX_PER_ACCOUNT = 60;

    /** The content's type => the extension it is kept under. No SVG: a browser would run its scripts. */
    private const IMAGES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const MIN_EDGE = 300;

    private const MAX_EDGE = 10000;

    private const MAX_PIXELS = 40000000;

    public const CASES = ['asis', 'upper', 'title'];

    public const DEFAULTS = ['font_key' => CardFonts::DEFAULT, 'font_size' => 70, 'color' => '#1f2933', 'name_y' => 50.0, 'letter_case' => 'asis', 'letter_spacing' => 0, 'max_width' => 80, 'pdf_page' => 1];

    /** The sample design, ready to try. Where its blank line is, measured on the picture. */
    public const SAMPLE = ['name' => 'Sample card', 'file' => 'sample-card.jpg', 'w' => 1200, 'h' => 1800, 'font_key' => 'great-vibes', 'font_size' => 88, 'color' => '#8a6414', 'name_y' => 56.6];

    /** The owner's two real cards for the public demo, when switched on (demoCards; files in the portal's resources: CardFiles), the first the default. Each name is set in the gap under "warmly invite", clear of the heart below it and inside the printed rules, in the bronze of the card's own names. */
    public const DEMO = [
        ['name' => 'Gold arch card', 'file' => 'demo-card-gold.png', 'w' => 1024, 'h' => 1536, 'font_key' => 'great-vibes', 'font_size' => 58, 'color' => '#8a560c', 'name_y' => 57.9, 'max_width' => 72],
        ['name' => 'Full photo card', 'file' => 'demo-card-photo.png', 'w' => 1024, 'h' => 1536, 'font_key' => 'great-vibes', 'font_size' => 44, 'color' => '#7a4a14', 'name_y' => 56.9, 'max_width' => 72],
    ];

    /** @var list<string> files written by the save under way, for takingBackFiles() */
    private array $written = [];

    /** @return Collection<int, EventCardTemplate> an event's designs: the default, then the newest */
    public function forEvent(int $companyId, int $eventId): Collection
    {
        return EventCardTemplate::withoutGlobalScopes()->where('company_id', $companyId)->where('budget_program_id', $eventId)->where('is_deleted', 0)
            ->orderByDesc('is_default')->orderByDesc('id')->get();
    }

    public function find(int $companyId, int $eventId, int $id): ?EventCardTemplate
    {
        return EventCardTemplate::withoutGlobalScopes()->where('company_id', $companyId)->where('budget_program_id', $eventId)->where('is_deleted', 0)->find($id);
    }

    /**
     * The figures of a design, checked: unknown fields are dropped, and a figure out of range is refused in plain words.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function metrics(array $input): array
    {
        $font = (string) ($input['font_key'] ?? '');
        if (! CardFonts::exists($font)) {
            throw BusinessRuleException::make('validation', 'Choose one of the fonts on the list.');
        }
        $color = strtolower(trim((string) ($input['color'] ?? '')));
        if (preg_match('/^#[0-9a-f]{6}$/', $color) !== 1) {
            throw BusinessRuleException::make('validation', 'Choose a colour for the name.');
        }
        $case = (string) ($input['letter_case'] ?? 'asis');
        if (! in_array($case, self::CASES, true)) {
            throw BusinessRuleException::make('validation', 'Choose how the name is written: as typed, capitals or first letters.');
        }

        return [
            'font_key' => $font,
            'color' => $color,
            'letter_case' => $case,
            'font_size' => self::number($input, 'font_size', 8, 400, 'The size of the name'),
            'name_y' => round((float) self::number($input, 'name_y', 0, 100, 'The place of the name', true), 3),
            'letter_spacing' => self::number($input, 'letter_spacing', -50, 500, 'The space between letters'),
            'max_width' => self::number($input, 'max_width', 20, 100, 'The width a long name fits into'),
            'pdf_page' => self::number($input, 'pdf_page', 1, 500, 'The page of the PDF'),
        ];
    }

    /** @param array<string, mixed> $input */
    private static function number(array $input, string $field, int $min, int $max, string $what, bool $decimal = false): int|float
    {
        $value = $input[$field] ?? null;
        if (! is_numeric($value) || (float) $value < $min || (float) $value > $max) {
            throw BusinessRuleException::make('validation', "{$what} must be between {$min} and {$max}.");
        }

        return $decimal ? (float) $value : (int) round((float) $value);
    }

    private static function cleanName(mixed $name): string
    {
        $name = Str::squish(strip_tags((string) $name));
        if ($name === '' || mb_strlen($name) > 120) {
            throw BusinessRuleException::make('validation', 'Give the design a name of up to 120 letters.');
        }

        return $name;
    }

    /** @param bool $demoToo whether the shared demo may not do it: it takes no files from visitors, and its cards stay on it */
    private function mayEdit(User $by, BudgetProgram $program, string $what, bool $demoToo = false): void
    {
        if (! $demoToo) {
            PublicDemo::guard($program->company_id, $what);
        }
        if (! BudgetAccess::canEditSettings($by, (int) $program->id)) {
            throw BusinessRuleException::make('forbidden', "Only the event's chair or the owner can change its invitation cards.");
        }
    }

    /**
     * A new design from a picture, or from a PDF with the picture of its chosen page.
     *
     * @param  array<string, mixed>  $input  name, is_default and the figures (metrics())
     */
    public function create(User $by, BudgetProgram $program, array $input, UploadedFile $card, ?UploadedFile $picture = null): EventCardTemplate
    {
        $this->mayEdit($by, $program, 'adding an invitation card');
        $cid = (int) $program->company_id;
        $eid = (int) $program->id;
        $name = self::cleanName($input['name'] ?? '');
        $metrics = self::metrics($input);
        $uuid = (string) Str::uuid();

        return $this->takingBackFiles(fn () => DB::transaction(function () use ($by, $cid, $eid, $name, $metrics, $input, $card, $picture, $uuid) {
            DB::table('budget_programs')->where('id', $eid)->lockForUpdate()->value('id');
            $this->withinLimits($cid, $eid);
            $columns = $this->keepFiles($cid, $eid, $uuid, $card, $picture);
            $count = $this->forEvent($cid, $eid)->count();
            $default = $count === 0 || filter_var($input['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($default) {
                $this->clearDefault($cid, $eid);
            }

            return EventCardTemplate::withoutGlobalScopes()->create(['uuid' => $uuid, 'company_id' => $cid, 'budget_program_id' => $eid, 'name' => $name, 'is_default' => $default,
                'created_by_id' => $by->id, 'updated_by_id' => $by->id] + $metrics + $columns);
        }));
    }

    /**
     * Change a design: its figures, and its picture when a new one is sent (the old files go).
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $by, EventCardTemplate $template, array $input, ?UploadedFile $card = null, ?UploadedFile $picture = null): EventCardTemplate
    {
        $program = $this->programOf($template);
        // In the demo a visitor may move the name, try fonts and save: the figures go back at its next upkeep (ensureDemo).
        $this->mayEdit($by, $program, 'changing the picture of an invitation card', $card === null && $picture === null);
        $name = self::cleanName($input['name'] ?? $template->name);
        $metrics = self::metrics($input);
        $cid = (int) $template->company_id;
        $eid = (int) $template->budget_program_id;
        $newFile = $card ?? $picture;
        $old = $newFile === null ? [] : [$template->background_path, $template->source_path];
        $template = $this->takingBackFiles(fn () => DB::transaction(function () use ($by, $template, $input, $name, $metrics, $cid, $eid, $card, $picture, $newFile) {
            DB::table('budget_programs')->where('id', $eid)->lockForUpdate()->value('id');
            $columns = $newFile === null ? [] : $this->keepFiles($cid, $eid, (string) Str::uuid(), $newFile, $card === null ? null : $picture);
            if (array_key_exists('is_default', $input)) {
                $default = filter_var($input['is_default'], FILTER_VALIDATE_BOOLEAN);
                if ($default) {
                    $this->clearDefault($cid, $eid, (int) $template->id);
                }
                $columns['is_default'] = $default;
            }
            $template->fill(['name' => $name, 'updated_by_id' => $by->id] + $metrics + $columns)->save();

            return $template;
        }));
        foreach ($old as $path) {
            CardFiles::delete($path);
        }

        return $template;
    }

    public function setDefault(User $by, EventCardTemplate $template, bool $default): void
    {
        $this->mayEdit($by, $this->programOf($template), 'changing an invitation card', true);
        DB::transaction(function () use ($template, $default) {
            DB::table('budget_programs')->where('id', $template->budget_program_id)->lockForUpdate()->value('id');
            if ($default) {
                $this->clearDefault((int) $template->company_id, (int) $template->budget_program_id, (int) $template->id);
            }
            $template->is_default = $default;
            $template->save();
        });
    }

    /** A design goes, with its files. The log keeps the names it was made for. */
    public function delete(User $by, EventCardTemplate $template): void
    {
        $this->mayEdit($by, $this->programOf($template), 'deleting an invitation card');
        $paths = [$template->background_path, $template->source_path];
        DB::transaction(function () use ($template) {
            DB::table('budget_programs')->where('id', $template->budget_program_id)->lockForUpdate()->value('id');
            $wasDefault = (bool) $template->is_default;
            $template->is_default = false;
            $template->is_deleted = true;
            $template->save();
            if ($wasDefault) {
                $next = $this->forEvent((int) $template->company_id, (int) $template->budget_program_id)->first();
                if ($next) {
                    $next->is_default = true;
                    $next->save();
                }
            }
        });
        foreach ($paths as $path) {
            CardFiles::delete($path);
        }
    }

    /** The sample design, for someone who wants to try it before they have their own card. */
    public function addSample(User $by, BudgetProgram $program): EventCardTemplate
    {
        $this->mayEdit($by, $program, 'adding an invitation card');

        return $this->sample($program, (int) $by->id);
    }

    /**
     * The cards a demo event should have: the two real ones when they are switched on and their files are here
     * (config demo.real_cards), else the made-up sample, so a demo never points at a picture that is not there.
     *
     * @return list<array<string, mixed>>
     */
    public static function demoCards(): array
    {
        if (config('demo.real_cards')) {
            $there = array_filter(self::DEMO, fn ($d) => CardFiles::path(CardFiles::SAMPLE.$d['file']) !== null);
            if (count($there) === count(self::DEMO)) {
                return self::DEMO;
            }
        }

        return [self::SAMPLE + ['max_width' => 70]];
    }

    /**
     * A demo event's cards, made or put back as they started, so each visitor finds them right whatever the last one
     * tried. A shipped card that is no longer wanted comes off the event: switching the real cards off takes them away at the
     * next upkeep, and switching them on brings them back. Saved only where something differs. No lock: this is the demo's own upkeep.
     */
    public function ensureDemo(BudgetProgram $program, int $byId): void
    {
        $cid = (int) $program->company_id;
        $eid = (int) $program->id;
        $wanted = self::demoCards();
        DB::transaction(function () use ($cid, $eid, $byId, $wanted) {
            DB::table('budget_programs')->where('id', $eid)->lockForUpdate()->value('id');
            $paths = array_map(fn ($d) => CardFiles::SAMPLE.$d['file'], $wanted);
            foreach ([...self::DEMO, self::SAMPLE] as $shipped) {
                $path = CardFiles::SAMPLE.$shipped['file'];
                if (! in_array($path, $paths, true)) {
                    foreach (EventCardTemplate::withoutGlobalScopes()->where('company_id', $cid)->where('budget_program_id', $eid)->where('background_path', $path)->where('is_deleted', 0)->get() as $old) {
                        $old->is_default = false;
                        $old->is_deleted = true;
                        $old->save();
                    }
                }
            }
            foreach ($wanted as $i => $d) {
                $path = CardFiles::SAMPLE.$d['file'];
                $row = EventCardTemplate::withoutGlobalScopes()->where('company_id', $cid)->where('budget_program_id', $eid)->where('background_path', $path)->first()
                    ?? new EventCardTemplate(['company_id' => $cid, 'budget_program_id' => $eid, 'source_type' => 'image', 'background_path' => $path,
                        'background_mime' => str_ends_with((string) $d['file'], '.png') ? 'image/png' : 'image/jpeg', 'background_w' => $d['w'], 'background_h' => $d['h'], 'source_bytes' => 0, 'created_by_id' => $byId]);
                $row->fill(['name' => $d['name'], 'font_key' => $d['font_key'], 'font_size' => $d['font_size'], 'color' => $d['color'], 'name_y' => $d['name_y'],
                    'letter_case' => 'asis', 'letter_spacing' => 0, 'max_width' => $d['max_width'], 'pdf_page' => 1, 'is_default' => $i === 0, 'is_deleted' => false, 'updated_by_id' => $byId]);
                if (! $row->exists || $row->isDirty()) {
                    $row->save();
                }
            }
        });
    }

    private function sample(BudgetProgram $program, int $byId): EventCardTemplate
    {
        $s = self::SAMPLE;

        return DB::transaction(function () use ($program, $byId, $s) {
            $cid = (int) $program->company_id;
            $eid = (int) $program->id;
            DB::table('budget_programs')->where('id', $eid)->lockForUpdate()->value('id');
            $this->withinLimits($cid, $eid);

            return EventCardTemplate::withoutGlobalScopes()->create([
                'company_id' => $cid, 'budget_program_id' => $eid, 'name' => $s['name'], 'source_type' => 'image',
                'background_path' => CardFiles::SAMPLE.$s['file'], 'background_mime' => 'image/jpeg', 'background_w' => $s['w'], 'background_h' => $s['h'],
                'source_bytes' => 0, 'pdf_page' => 1, 'font_key' => $s['font_key'], 'font_size' => $s['font_size'], 'color' => $s['color'], 'name_y' => $s['name_y'],
                'letter_case' => 'asis', 'letter_spacing' => 0, 'max_width' => 70, 'is_default' => $this->forEvent($cid, $eid)->isEmpty(), 'created_by_id' => $byId, 'updated_by_id' => $byId,
            ]);
        });
    }

    private function withinLimits(int $cid, int $eid): void
    {
        if ($this->forEvent($cid, $eid)->count() >= self::MAX_PER_EVENT) {
            throw BusinessRuleException::make('card_limit', 'An event can keep '.self::MAX_PER_EVENT.' card designs. Delete one you no longer use.');
        }
        if (EventCardTemplate::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->count() >= self::MAX_PER_ACCOUNT) {
            throw BusinessRuleException::make('card_limit', 'This account keeps '.self::MAX_PER_ACCOUNT.' card designs. Delete some you no longer use.');
        }
    }

    /**
     * A save that fails leaves no files behind.
     *
     * @template T
     *
     * @param  callable(): T  $save
     * @return T
     */
    private function takingBackFiles(callable $save): mixed
    {
        try {
            return $save();
        } catch (\Throwable $e) {
            foreach ($this->written as $path) {
                CardFiles::delete($path);
            }
            throw $e;
        } finally {
            $this->written = [];
        }
    }

    /** The others stop being the default. Not the one becoming it: its own unsaved model would see no change to save. */
    private function clearDefault(int $cid, int $eid, ?int $except = null): void
    {
        foreach ($this->forEvent($cid, $eid)->where('is_default', true)->where('id', '!=', $except) as $t) {
            $t->is_default = false;
            $t->save();
        }
    }

    private function programOf(EventCardTemplate $template): BudgetProgram
    {
        return BudgetProgram::withoutGlobalScopes()->where('company_id', $template->company_id)->findOrFail($template->budget_program_id);
    }

    /**
     * Check an upload by its content and keep it. An image stands alone; a PDF needs the picture of its page.
     *
     * @return array<string, mixed> the row's file columns
     */
    private function keepFiles(int $cid, int $eid, string $uuid, UploadedFile $card, ?UploadedFile $picture): array
    {
        $type = (string) $card->getMimeType();
        $isPdf = $type === 'application/pdf';
        if ($isPdf) {
            $head = (string) file_get_contents((string) $card->getRealPath(), false, null, 0, 5);
            if ($head !== '%PDF-') {
                throw BusinessRuleException::make('card_file', 'This file is not a PDF we can read.');
            }
            if ($picture === null) {
                throw BusinessRuleException::make('card_file', 'The page of the PDF could not be read. Please try again.');
            }
        }
        $image = $isPdf ? $picture : $card;
        $imageType = (string) $image->getMimeType();
        $ext = self::IMAGES[$imageType] ?? throw BusinessRuleException::make('card_file', 'Send the card as a PNG, a JPG or a PDF.');
        $size = @getimagesize((string) $image->getRealPath());
        if ($size === false) {
            throw BusinessRuleException::make('card_file', 'This picture could not be read. Please save it again and retry.');
        }
        [$w, $h] = $size;
        if (min($w, $h) < self::MIN_EDGE || max($w, $h) > self::MAX_EDGE || $w * $h > self::MAX_PIXELS) {
            throw BusinessRuleException::make('card_file', 'The picture must be at least '.self::MIN_EDGE.' pixels on each side and at most '.self::MAX_EDGE.'. This one is '.$w.' by '.$h.'.');
        }
        $background = CardFiles::store($image, $cid, $eid, $uuid, $ext);
        $this->written[] = $background;
        $source = null;
        if ($isPdf) {
            $source = CardFiles::store($card, $cid, $eid, $uuid.'-source', 'pdf');
            $this->written[] = $source;
        }

        return [
            'source_type' => $isPdf ? 'pdf' : 'image', 'background_path' => $background, 'background_mime' => $imageType, 'background_w' => $w, 'background_h' => $h,
            'source_path' => $source, 'source_bytes' => $isPdf ? (int) $card->getSize() : 0,
        ];
    }
}
