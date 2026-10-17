# Budget Pro roadmap: master it, stabilise it, simplify it, then grow it

**Written:** 17 October 2026, from a full read of the three repos that make Budget Pro:

| Repo | Role |
|---|---|
| `htdocs/budget-pro` (this repo, the **kernel**) | Laravel 10. Owns the schema, the services, `/api/v1`, the scheduler and the classic admin |
| `htdocs/budget-pro-new` (the **portal**) | Laravel 12 and Livewire 3. The modern web interface and the public site; loads the kernel's models and services |
| `Desktop/github/budget-pro-mobo` (the **apps**) | Flutter, four flavors: Budget Pro, Kuku Tally, Shelfie, Pledge & Budget |

**Status:** plan. Nothing here is built unless a file says so.

## Where Budget Pro is

The hard work is done:

- **The master plan is complete.** `SHOP_ONBOARDING_OFFLINE_MASTER_PLAN.md` has 63 of 63 items ticked, and all twelve critical findings are fixed in the code: roles, offline POS, event-sourced stock, locks and idempotency, the discount and credit ledger, numbering, and more.
- **The supermarket tier is built:** price levels, promotions, loyalty, gift cards, consignment, landed cost, smart reorder, EFRIS adapter.
- **The standalone edition** has signed licences, an installer, releases and safe updates.
- **The family is in place:** one account and four apps with modules chosen at sign-up, `config/apps.php`, four Flutter flavors and the portal following the modules.
- **The public site** has real screenshots, a public demo and performance budgets.

What stands between this and a product that never surprises anyone:

1. **Config that silently switches things off.** `.env.example` is close to stock Laravel. With the defaults, mail is off, WhatsApp and SMS go to the log, the queue is `sync`, and the site's WhatsApp and phone are empty. OTPs and invites can quietly never arrive.
2. **Sign-in gaps.**
   - The classic sign-in has no attempt limit, and the master password (a plaintext env value, logged only to a file) works there.
   - Passwords need 6 characters at sign-up but 8 for staff.
   - There is no 2FA.
3. **The API does not check modules,** so a poultry-only account can call shop endpoints. Tenancy in the API relies on hand-written company filters, because the global scope skips Sanctum.
4. **Money is floats** with `round()`. Budget amounts are cast to `int`, and pledges have no payment history.
5. **Two of the four modules have no portal screens.** Poultry and Pledge & Budget live only in the classic admin and the app.
6. **The apps are not released as four apps:**
   - three application IDs are unconfirmed;
   - there is no git remote, no CI, no crash reports and no push;
   - support details are hard-coded.
7. **Interface weight and reach:**
   - 1.1 MB of Font Awesome and 227 KB of admin CSS, with no budget;
   - no dark mode, no PWA manifest;
   - 8 of 190 views translated;
   - bulk actions on only 3 lists;
   - no browser tests.
8. **Docs that contradict the code:** about 20 stale files (12,000 lines) from 2025, a standalone plan that says "nothing built", and a misleading test-results cache.

## The files

| # | File | What it covers |
|---|---|---|
| 01 | [System review](01-system-review.md) | The facts, with file paths |
| 02 | [Stabilise and secure](02-stabilise-and-secure.md) | Config, sign-in, module gating, tenancy, money, sync, tests, CI, docs, operations |
| 03 | [Interface](03-interface.md) | The portal and classic admin: speed, design, libraries, simplicity, accessibility, languages, tests |
| 04 | [Modules and new things](04-modules-and-new-things.md) | Shop, supermarket, poultry, pledges and budget, finance: what to perfect and what to add |
| 05 | [The four apps](05-the-four-apps.md) | Release, safety, push, crash reports, support, finishing the excellence plan |
| 06 | [Selling, SEO and WhatsApp](06-selling-seo-and-whatsapp.md) | Site, search, funnel, prices per module, the WhatsApp number everywhere |
| 07 | [Delivery plan](07-delivery-plan.md) | Phases, measures, definition of done, owner decisions |

The command that executes it is [`../agent-command.md`](../agent-command.md).

## Rules that hold for every item

They come from the existing docs and ADRs and are not up for change:

- **The kernel owns the schema and the rules.** The portal never defines classes in kernel namespaces and never adds migrations (`budget-pro-new/docs/shop-conventions.md`, `KernelShadowingTest`).
- **Writes go through kernel services** (`attempt()` in the portal), and validation rules come from `app/Support/Rules`.
- **ADR 0001 to 0008:**
  - one offline-first sync engine with server sequence cursors;
  - event-sourced stock;
  - a ledger per payment;
  - per-company roles;
  - a free-tier fallback;
  - locations and FEFO batches;
  - merge, not unique keys;
  - host-agnostic operations.
- **One codebase, two editions** (SaaS and standalone).
- **Reuse before building.** Every item names the file it extends.

## Priority tags

- **P0:** risk to money, data or sign-in, or a blocker to selling. Do first.
- **P1:** a big step to the standard.
- **P2:** after P0 and P1.

## The one number

The support WhatsApp is **+256 783 204 665** (digits `256783204665`). The portal already prints WhatsApp links when `SITE_WHATSAPP` is set (`config/site.php`), and the app already looks for `meta.support.whatsapp`. Both are empty today. 06 §4 makes the number flow from one setting to every surface.
