# Agent command: master, stabilise, simplify and grow Budget Pro

Copy everything below the line to the coding agent, from `/Applications/MAMP/htdocs/budget-pro`.

---

You are the lead engineer of **Budget Pro**, a family of business apps built on one backend:

- **Budget Pro** (everything in one app);
- **Shelfie** (shops and supermarkets);
- **Kuku Tally** (poultry farms);
- **Pledge & Budget** (weddings and events).

The system lives in three repos:

| Repo | What it is |
|---|---|
| `/Applications/MAMP/htdocs/budget-pro` | The **kernel**. Laravel 10: schema, services, `/api/v1`, scheduler, classic admin |
| `/Applications/MAMP/htdocs/budget-pro-new` | The **portal**. Laravel 12 and Livewire 3: the modern web interface and the public site, on the kernel's models and services |
| `/Users/mac/Desktop/github/budget-pro-mobo` | The **apps**. Flutter, four flavors from one codebase |

**The hard work is done:**

- the shop and onboarding master plan is complete (63 of 63 items, all twelve critical findings fixed);
- the supermarket tier is built;
- the standalone edition sells and updates itself;
- the four-app family exists.

Your job now is to **master** it, **stabilise** every part, **simplify** every screen, **introduce** the few new things users will ask for next, and bring it all to **world standard and local standard** at once.

**Nothing is built from scratch.** Every item in the plan names the existing service, component, ADR or pattern it extends. Find it, extend it, keep its tests green. Building a second version of something that exists is a failure.

## 1. Read before you touch anything

1. **The roadmap, your plan of record:** `docs/roadmap/README.md` and files `01` to `07` in this repo. Each item has its priority (P0, P1, P2), the files it extends, and its definition of done.
2. **The kernel's truth:**
   - `SHOP_ONBOARDING_OFFLINE_MASTER_PLAN.md` (what was fixed and how);
   - `docs/adr/0001` to `0008`;
   - `docs/MOBILE_API_CONTRACT.md`, `docs/STANDALONE_EDITION_PLAN.md`;
   - `DECISIONS.md`, `CHANGELOG.md`;
   - `docs/runbooks/*`.
   - Treat the 2025 files (`SESSION_*`, `PHASE_*`, `ENVATO_*`, the old guides) as stale until 02 §10 archives them.
3. **The portal's rules:**
   - `budget-pro-new/docs/shop-conventions.md` (never define kernel classes, never add migrations, writes through kernel services, the screen shape and its five test proofs);
   - `docs/FAMILY_PLAN.md`, `docs/POWER_PLAN.md`, `docs/SUPERMARKET_PLAN.md`, `docs/deployment.md`.
4. **The app's rules:** `budget-pro-mobo/docs/MOBILE_EXCELLENCE_PLAN.md`, `docs/FLAVORS.md`, and the product briefs in `Desktop/github/{kuku-tally,shelfie,pledge-and-budget}/README.md`.
5. **Proven patterns from the owner's other products:**
   - True-Doctor (`/Applications/MAMP/htdocs/true-doctor`, its `docs/agents/` and `docs/roadmap/`);
   - its app (`Desktop/github/true-doctor-mobo`: API client, secure session, outbox, diagnostics).
   - Port the pattern, not the copy.

## 2. The support number goes everywhere

The support WhatsApp is **+256 783 204 665** (digits `256783204665`).

- Build `App\Support\SupportContact` in the kernel (roadmap 06 §4).
- Make the portal's `config/site.php` read the same values.
- Add a `support` block to `GET /api/v1/meta`, and make the four apps read it instead of the hard-coded copy in `ContactUsScreen.dart`.
- Show it on every surface in 06 §4: the site, the portal and classic admin, emails, error pages, the optional receipt line, the apps and the store listings.
- Use prefilled messages per context, and never include a customer's name, an amount or a phone number.
- A CI scan fails if the number is typed anywhere outside config, env examples and docs.

## 3. Phase 0 first: stabilise and lock

Before any new feature:

- **A complete `.env.example`** in kernel and portal, and a `config:doctor` that fails a production deploy when mail is off, the queue is `sync`, messaging is `log`, payment keys are in test mode, or the support facts are empty.
- **Sign-in:**
  - attempt limits on the classic sign-in;
  - the master password hashed, limited to super admins from allowed places, audited in a table and alerted to the owner;
  - one password rule (8 characters plus a breached check).
- **Gating and tenancy:**
  - `api.module` middleware on every `/api/v1` group, with a test that walks every route;
  - `CompanyScope` on the Sanctum guard;
  - the unscoped tenant models scoped;
  - isolation tests for every tenant model and route group.
- **Fresh suite runs** in all three repos with the true counts written down (delete the stale `.phpunit.result.cache`). CI for the portal (`npm run build`, Vitest) and for the app.
- **The app repo** gets its private remote.
- **`LIVE_ACCOUNTS.local.md`** moves out of the web-served folder; scan all histories for secrets.

## 4. Then simplify and perfect (roadmap 03, 04, 05)

- **Measure the ten daily journeys first,** then:
  - one main action per screen;
  - a next step after every task;
  - required fields only;
  - a plain-word glossary shared by web and app;
  - suggestions from the data.
- **The interface:**
  - SVG icons instead of 1.1 MB of Font Awesome;
  - admin CSS split by area with size budgets in CI;
  - dark mode and density;
  - a `/_kit` page;
  - bulk actions and saved views;
  - the large Livewire components split without changing behaviour;
  - translations screen by screen;
  - WCAG AA with axe in browser tests;
  - a PWA manifest for the till.
- **Add libraries only when they beat hand-written code:** wrapped once, loaded per page, self-hosted, recorded with licence and size. No React, Vue, Inertia, Tailwind or Bootstrap.
- **The missing portal screens** for poultry and Pledge & Budget, built with the existing kit on the kernel's models. Then retire their classic screens.
- **Money and pledges:**
  - a `Money` value object on bcmath across sales, payments, promotions and finance, with property tests;
  - pledge payment history and committee roles.
- **The four apps:**
  - confirm the three new application IDs;
  - four store listings, then closed tests, then production;
  - crash reports, push with deep links;
  - finish C3 and E6;
  - remove `flutx`;
  - move pledges into a proper module folder;
  - each app's home screen fits its user.
- **New things worth having (roadmap 04),** each serving a real daily job:
  - **WhatsApp:** the owner's daily summary, customer statements, purchase orders to suppliers, pledge reminders and committee progress shares;
  - suggestions for every module;
  - event types beyond weddings;
  - poultry vaccine reminders and batch profit;
  - EFRIS certification.

## 5. How to work

1. **Plan the phase.** Start each phase of `docs/roadmap/07-delivery-plan.md` with a short plan: each item, the files you extend in which repo, the tests you add, and any shared file you must touch. When the code contradicts the roadmap, the code is the truth: note it, adapt minimally, record it in the right decisions file.
2. **One item at a time, small commits, in the right repo.**
   - Schema and rules change in the kernel.
   - Screens change in the portal, through kernel services.
   - Phone work happens in the app.
   - Implement fully, with no stubs and no TODOs.
3. **Gates before every commit:**
   - **Kernel:** Pint, PHPStan, tests.
   - **Portal:** Pint, larastan, tests, `npm run build` (commit `public/build`; the host has no Node), Vitest.
   - **App:** `flutter analyze`, `flutter test`.
4. **Look at what you built.** Screenshots at 390, 768 and 1366 px, light and dark, for the portal; the harness for each flavor. Fix what is wrong before calling it done.
5. **Fresh eyes on danger.** Anything touching money, stock, tenancy, sync, sign-in, the master password or messages to customers gets a second review against the ADRs and `shop-conventions.md` before it is done.
6. **Report at the end of each phase:**
   - what shipped, with screenshots;
   - the true test results of each repo;
   - what was reused;
   - what was not done and why;
   - the owner decisions from 07 §4.

   Wait for "continue" before the next phase.

## 6. Be creative, inside the rules

The roadmap is the floor. Propose better ideas in the phase plan with their reason and cost, and build them once approved. Judge each idea by four questions:

1. Does it save a shopkeeper, farmer or treasurer time today?
2. Does it make money or stock more correct?
3. Does it help the next customer find and trust us?
4. Does it work on a cheap Android phone with no network?

## 7. Never

- **Never break the kernel–portal contract:** no kernel classes in the portal, no migrations in the portal, no writes around kernel services.
- **Never weaken an ADR silently.** A change needs a new ADR.
- **Never use floats for new money code.** Never edit a ledger or stock row in place; post a new one.
- **Never send real WhatsApp, SMS or payment traffic** from development or tests. The drivers stay `log` and simulated there.
- **Never commit secrets,** `.env*` or `LIVE_ACCOUNTS*`.
- **Never push, deploy or release** unless the owner asks.

Begin with **Phase 0**: read, write the phase plan, then the complete `.env.example` and `config:doctor`, sign-in hardening, API module gating and tenancy, and `SupportContact`.
