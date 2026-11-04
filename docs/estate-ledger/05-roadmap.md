# 05. Estate Ledger: roadmap and progress

Work packages in build order. Tick a box only when its code and tests are in and green. Owner rules for every package:
light mode only on the web; no AI-template look (flat, hairlines, no accent stripes, no big shadows); square corners on
Estate Ledger's screens; Estate Ledger never names another app; lean testing (run your own tests once, the integrator runs full
suites once); no push or deploy without the owner's word.

## WP0 Docs

- [x] 03 proposal, 04 architecture, ADR 0010, decisions, README
- [x] Private study of the earlier system + "what Estate Ledger does better" (`budget-pro-new/docs/estate-ledger/00-older-estate-system-study.md`, 2,520 lines)

## WP1 Kernel foundation

- [x] Migrations (04 §3, block `2026_10_24_7xxxxx`) and models (`Rental*`, scopes, immutability)
- [x] Plumbing (04 §2 rows 1–20): module, exclusive rule, onboarding, permissions and roles, API module, plan line, quotas, plans seeder, ShopMenu, SupportContact, TenantModels, files purposes; Estate Ledger's own name in welcome, codes, invites, mail footers (RentalsIndependenceTest)
- [x] `config/rentals.php`, `RentalsModule::setup` and settings

## WP2 Kernel money engine

- [x] `Minor`, `Periods`, `Splits` (pure, unit tests: 2,000 random bills)
- [x] `RentalLedger` (lock, balanced post, reversal) and `RentalAccess`, `RentalAudit`
- [x] Billing (raise, one-off, void, late fees, flat commission per month of rent), Payments (receive, waive, credit, unapply, refunds, reverse)
- [x] Leases (move in incl. import, notice, renew, move out with deposit settlement and unused days, cancel, rent change, items)
- [x] Landlord accounts and statements, payouts, expenses, cash book and transfers, meters, maintenance
- [x] Arrears and follow-ups, messages and links, documents (`RentalsOperationsTest`, `RentalsEngineDefectsTest`)
- [x] `rentals:daily`, `rentals:check`, property test (300 random operations), concurrency test (pcntl_fork)

## WP3 Kernel API, reports, sign-ins, import, demo

- [x] Reports registry and exports (CSV, XLSX, PDF): `RentalsReportsTest`
- [x] Staff API (04 §9) with ApiPermissionMap lines; isolation and scope tests: `RentalsApiTest`
- [x] Tenant and landlord sign-in (`rental_logins`, portal-auth API, `/me` API): `RentalsPersonApiTest`
- [x] Import (CSV templates, dry run, opening balances, `rentals:import`): `RentalsImportTest`
- [x] Demo (`RentalsDemo`, builder, data, `rentals:demo`, schedule), plans seeder

## WP4 Portal

- [x] Plumbing (04 §2 rows 21–26)
- [x] Staff screens (04 §10.1) with tests (five proofs) and PDFs (receipt, statements, lease summary)
- [x] People portal `/my` (tenant, landlord)
- [x] Site `estate-ledger` (content, page, pricing, icons, OG image), demo page, independence guard test, `site:check`
- [x] One browser pass (page errors, 390 and 1366, light only)

## WP5 App

- [ ] Flavor `estate-ledger` (Gradle, res, icons, splash, `AppFlavor`, square theme flag, strings EN/SW)
- [ ] Data layer (`KeyrollApi`, models, cache, offline queue)
- [ ] Staff screens, tenant screens, landlord screens (04 §11.2)
- [ ] Tests (`flutter analyze`, `flutter test test/rentals`), release AAB from a fresh-install check

## WP6 Integration

- [x] Full kernel and portal suites once; demo built locally; `rentals:check` clean on the demo
- [ ] Store listing text and screenshots (`~/Desktop/github/keyroll/store`)
- [x] Deploy (done 2026-11-04): migrations, seeders, env `APP_ESTATE_LEDGER_DOMAIN`, subdomain + AutoSSL, demo
