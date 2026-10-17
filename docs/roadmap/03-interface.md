# 03. The interface: simpler, lighter, everywhere the same

**Goal:**

- A shop owner who has never used software sells in their first 3 minutes.
- A farmer records today's eggs in 20 seconds.
- A wedding treasurer records a pledge payment in 3 taps.
- Every screen in the portal loads fast on a cheap laptop and a 390 px phone, in light or dark mode, in English, Swahili, Luganda or French.

**Keep the architecture.** Livewire 3, Alpine (through Livewire), the persisted shell with `wire:navigate`, the hand-written design system with `--br` and the `bp-` tokens, and the kit (`x-ui.*`, `x-dash.*`, `WithTable`, `CrudModal`, `PeeksRecords`) are right. Follow `portal/docs/shop-conventions.md` on every screen. Do not add React, Vue, Inertia, Tailwind or Bootstrap.

**Add small, maintained libraries only where they beat hand-written code.** Wrap each once, load it only on the pages that need it, self-host it, and record it in the portal's decisions with its licence and size.

---

## 1. Simplify first (P0 to P1)

**Measure the ten daily journeys before changing anything.** Count clicks, fields and seconds on the portal and the app for:

1. Sign up to the first sale.
2. Sell with cash, mobile money and credit.
3. Receive stock.
4. Count stock.
5. Take a debt payment.
6. Close the shift.
7. Record today's poultry card.
8. Record a pledge payment.
9. Add a budget item.
10. Find anything.

Record them in `portal/docs/journeys.md` with targets. Re-measure after each change, with screenshots.

**Then apply these rules everywhere:**

- **One main action per screen,** big and first. Everything else goes in the actions menu (`x-ui.actions-menu` exists).
- **No dead ends:** every finished task offers the next step (`x-ui.next-step` exists): after a sale, "Send receipt on WhatsApp"; after receiving stock, "Print shelf labels".
- **Required fields only.** Optional ones sit behind "More details" and are suggested later by "Worth a look" (`GET /api/v1/dashboard/suggestions` exists).
- **Plain words** from one glossary file (for example "Money in", "Owed to you", "Running low"), shared by the portal and the app.
- **Suggestions where the data knows better:** the usual price, the last supplier, the usual quantity, reorder amounts (SmartReorder exists) and the next egg tray count from yesterday's lay rate.

## 2. Weight and speed (P1)

- **Replace Font Awesome** (1.1 MB, 955 uses) in the admin with inline SVG icons. The marketing site already does this with `components/site/icon.blade.php`; extend that one component with the admin's icons (or adopt `blade-ui-kit/blade-icons` with one set). Remove `public/vendor/fa` when the last use is gone.
- **Split `admin.css`** (227 KB built) into the shell plus per-area files loaded with the page (POS, stock, money, reports, settings). Remove unused rules with a coverage check from the screenshot crawl.
- **Budgets enforced by a test** reading the Vite manifest:
  - shell CSS at most 70 KB gzipped;
  - shell JS at most 40 KB gzipped;
  - each area file listed with its own budget.
  - CI fails on breach (add `npm run build` to CI).
- **Unused dependencies:** `alpinejs` and `dexie` are declared and never imported. Either use Dexie for the offline till's queue (§6) or remove both.
- **Server time:** keep the query budgets in `PerformanceBudgetTest`. Add a p95 timing check on production-sized seeded data for the five busiest screens (POS, products, sales, dashboard, stock movements), target 300 ms.

## 3. Design upgrades (P1)

- **Dark mode:**
  - a `[data-theme="dark"]` token set;
  - follows the device by default, with a toggle per user;
  - the shop's brand colour checked for 4.5:1 contrast in both modes.
- **Density:** compact and comfortable per user (comfortable by default on touch screens; the `pointer:coarse` rules exist).
- **A living kit page** at `/_kit` (platform admins only) showing every `x-ui` and `x-dash` component in every state, screenshotted in tests. This becomes the reference when building the new poultry and pledge screens.
- **Charts:**
  - keep the hand-written SVG components for small charts;
  - add `chart.js` (tree-shaken, loaded only on Reports and the dashboard's detail views) for interactive time series and comparisons.
- **Tables:**
  - bulk actions on every list where they make sense (stock, suppliers, debts, money records, promotions, team), using the existing pattern on Products, Sales and Customers;
  - saved views per user (filters and columns);
  - sticky headers.

## 4. Large screens made small (P1)

Split the biggest components into child components and Livewire Form objects without changing behaviour. Their tests stay green before and after:

| Component | Lines |
|---|---|
| `Pos/Index` | 1,127 |
| `Products/Index` | 1,021 |
| `Sales/Index` | 920 |
| `Settings/Business` | 708 (view 650) |
| `Money/Records` | 654 |
| `Money/Shifts` | 638 |
| `Onboarding/Wizard` | 571 |

Rules come from `kernel/app/Support/Rules`, as today.

## 5. The missing portal screens (P1, the family's biggest gap)

**Poultry (Kuku Tally on the web):**

- dashboard;
- batches with their detail;
- the daily card;
- the egg store;
- feed stock;
- health and vaccinations;
- sales and customers;
- expenses;
- reports with PDF;
- tasks.

They are built with the existing kit and on the kernel's `Poultry*` models and `PoultrySummary`. They follow the app's flows (`mobo/lib/poultry/screens/*`) so web and phone feel the same.

**Pledge & Budget on the web:**

- events;
- pledges and payments (with the history from 02 §6);
- budget items by category;
- the committee;
- reminders;
- the countdown;
- PDF reports.

**Then retire the classic screens** for these modules once the portal versions pass their tests. Turn on `SAAS_REDIRECT_TO_NEW_UI` for them, and list the remaining classic-only screens until none are left.

## 6. Offline in the browser (P1)

- **A PWA manifest** for the portal (name, icons, theme colour, start URL `/sell`), so the till installs on a tablet or desktop.
- **The offline till** (raw IndexedDB today) moves its queue to Dexie, if kept (§2), with Vitest tests using `fake-indexeddb` (installed).
- **Lift the limits** recorded in `SUPERMARKET_PLAN.md`, where safe: promotions computed offline from a cached price book, with the server's answer winning at sync.
- **Visible state:** "12 sales waiting to send" in the shell (the `tdOffline` component exists).

## 7. Languages (P1)

- Today `__()` appears in 8 of 190 views.
- **Convert the back office screen by screen,** in this order: the till, sign-up, the dashboard, products, stock, money, then the rest.
- **Files and locales:** `lang/{en,sw,lg,fr}.json` (305 keys today; `lg.json` has 30).
- **Shared words:** use the same keys as the app's l10n (`mobo/lib/l10n`) wherever the words match, so a shop sees the same word on the phone and the web.
- **A test** fails when a view prints English text outside `__()` in a converted area.

## 8. Accessibility (P1)

- WCAG 2.1 AA: contrast tested in both themes, labels on every input, focus trapped and returned in modals (exists), visible focus, screen-reader names on icon buttons, and keyboard use of the till.
- `axe-core` runs inside the browser tests (§9) on every core page.

## 9. Browser tests (P1)

- **Bring the screenshot harness into the repo** (today it lives in `/private/tmp`) as Playwright, or Pest browser tests, under `tests/Browser`.
- **The ten journeys from §1 as tests,** at 390, 768 and 1366 px, in light and dark mode.
- Screenshots are kept per run for review, with pixel diffs once stable.

## 10. Classic admin, until it is gone (P2)

- Keep it working and secure (02 §2), but build nothing new in it.
- Each release moves one more area to the portal.
- A page in the portal lists what is still classic-only, so the end is visible.
