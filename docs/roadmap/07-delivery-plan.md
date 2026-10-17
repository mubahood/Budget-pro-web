# 07. Delivery plan

## 1. Phases

Each phase ends with a report:

- what shipped, with screenshots at 390 px and desktop, light and dark;
- tests added and the true suite results for each repo;
- what was reused;
- what is left;
- decisions needed.

A phase starts only when the previous phase's P0 items are done.

### Phase 0: stabilise and lock (weeks 1 to 2)

| Item | File |
|---|---|
| Full `.env.example` in kernel and portal; `config:doctor` in deploy, `shop:check` and System health | 02 §1 |
| Attempt limits on the classic sign-in; master password hashed, restricted, audited, alerted | 02 §2 |
| One password rule (8 plus breached check) | 02 §2 |
| `api.module` middleware on every API group, plus the route-walk test | 02 §3 |
| `CompanyScope` on the Sanctum guard; unscoped tenant models scoped; isolation tests for every group | 02 §4 |
| Fresh suite runs in all three repos, true counts written; CI for portal (build, Vitest) and app; kernel mobile job fixed | 02 §8 |
| App remote created and pushed | 05 §1 |
| `LIVE_ACCOUNTS.local.md` out of the web folder; secrets scan of all histories | 02 §9 |
| `SupportContact`, `SITE_WHATSAPP` set, `meta.support`, app reads it; robots fixed | 06 §1, §4, 05 §5 |
| Queue on `database` everywhere | 02 §11 |

### Phase 1: simplify and release (weeks 3 to 6)

| Item | File |
|---|---|
| Measure the ten journeys; one main action per screen; next steps; plain-word glossary | 03 §1 |
| Font Awesome replaced with SVG icons; CSS split; asset budgets in CI | 03 §2 |
| Dark mode, density, the `/_kit` page | 03 §3 |
| Four app IDs confirmed; four store listings; closed tests; production | 05 §2 |
| Crash reports in kernel, portal and apps | 02 §11, 05 §4 |
| Docs archived and status lines corrected | 02 §10 |
| Pledge payment history and committee roles; decimal pledge money | 02 §6 |

### Phase 2: the missing screens and the money core (weeks 7 to 12)

| Item | File |
|---|---|
| Portal poultry screens and Pledge & Budget screens; classic redirect for them | 03 §5 |
| `Money` value object on bcmath; float columns migrated; property tests | 02 §5 |
| Bulk actions, saved views, large components split | 03 §3, §4 |
| Suggestions per module on web and app | 04 §7 |
| Pledge reminders, thank-yous, committee share, event types, event limits enforced | 04 §4 |
| Push notifications with deep links | 05 §3 |
| Browser tests for the ten journeys with axe | 03 §8, §9 |
| Prices per module decided and enforced | 06 §3 |

### Phase 3: depth and reach (months 4 to 6)

| Item | File |
|---|---|
| Languages across the back office | 03 §7 |
| PWA manifest; offline till queue on Dexie with tests | 03 §6 |
| EFRIS T130, T106, T108 and certification | 04 §2 |
| Retire v1 poultry sync and the pre-v1 API; poultry conflicts on ADR 0001 | 02 §7 |
| Daily owner summary, customer statements, supplier orders on WhatsApp | 04 §1 |
| Guides, calculators, videos; Organization schema | 06 §2 |
| Demo farm and demo event; the funnel on the platform screens | 06 §5 |
| 2FA for owners and platform admins | 02 §2 |
| Scheduler catch-up, uptime monitor and status page | 02 §11 |

### Phase 4 (month 6 onwards)

| Item | File |
|---|---|
| Kernel upgrade to Laravel 11, then 12 | 02 §11 |
| Retire the remaining classic screens | 03 §10 |
| Head-office multi-branch view; staff performance | 04 §2 |
| Bank and mobile-money statement import | 04 §3 |
| Kuku Tally extras: egg price trend, batch profit, disease log | 04 §5 |
| SQLCipher, remote wipe, biometrics in the apps | 05 §8 |

## 2. Measures

| Measure | Target to start |
|---|---|
| Suites green on every commit, in all three repos | always |
| OTP and invite delivery | 99% delivered (tracked by the messaging service) |
| Sign-up to first action (sale, daily card, pledge) | under 3 minutes for a shop |
| Trial to paid, per app | 25% |
| Weekly active users per account | 80% of seats |
| Crash-free sessions in the apps | 99.5% |
| Portal screens' p95 server time | under 300 ms |
| Public page weight | at most 200 KB (exists) |
| Uptime | 99.9% |
| Money discrepancies found by tests or support | zero |

## 3. Definition of done (every item)

- **Kernel and portal rules hold:**
  - the kernel owns schema and rules;
  - the portal writes only through kernel services, defines no kernel classes and adds no migrations;
  - every screen follows `shop-conventions.md`, including its five test proofs.
- **ADRs 0001 to 0008 hold,** or a new ADR records the change.
- **It reuses** the service, component or pattern named in its file.
- **Tests:**
  - new behaviour is covered;
  - money paths have idempotency and concurrency tests;
  - tenant models have isolation tests;
  - the full suites pass;
  - Pint and PHPStan are clean;
  - `npm run build` and Vitest pass for the portal;
  - `flutter analyze` and `flutter test` pass for the app.
- **Screens checked by eye** at 390 px and desktop, light and dark.
- **Docs:** the module's doc is updated, and decisions are in `DECISIONS.md` (kernel) or `docs/decisions.md` (portal and app).
- **No hard-coded contact, colour, price or placeholder.**
- **No push, deploy or release** unless the owner asks.

## 4. Decisions only the owner can make

1. The three new application IDs (05 §2).
2. Prices per module, per event and the bundle; event and batch limits (06 §3).
3. The WhatsApp Business setup for support and the message rates (06 §4).
4. Who may use the master password and from where, or whether to remove it (02 §2).
5. The error-tracking service and the status page host (02 §11).
6. The paused desktop print helper (04 §2).
7. The order in which classic screens retire (03 §10).
8. The kernel's Laravel upgrade window (02 §11).
9. Whether the "Powered by Budget Pro" line shows on receipts by default on SaaS (06 §4).
10. The private remote for the app repo (05 §1).
