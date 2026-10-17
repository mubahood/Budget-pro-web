# 06. Selling, SEO and WhatsApp everywhere

**What exists and stays (portal):**

- the family home and four app pages driven by `config/apps.php`;
- features, pricing from the kernel's `Plan` table, `/get-app`, the one-click public demo, contact, security, privacy, terms, `/own`;
- real screenshots and OG images (`scripts/site-shots.js`, `site-og.js`);
- SoftwareApplication JSON-LD with the live offer range, and FAQ schema;
- a 200 KB budget on public pages;
- `config/site.php` company facts printed only when set;
- WhatsApp links (footer, floating button, "Chat with support" in the admin menu) that appear when `SITE_WHATSAPP` is set.

---

## 1. Turn on what is already built (P0)

- **Set the `SITE_*` facts in production and in `.env.example`** (02 §1): `SITE_WHATSAPP=256783204665`, plus the support phone, email, hours, company legal name, registration and address. The site, the admin menu and the floating button light up at once.
- **Fix `public/robots.txt`.** Its `Sitemap:` points at the old `shop.budget-pro.schooldynamics.ug`. Serve robots from a route with the current absolute address, and disallow every signed-in path (add `/chat`, `/debts`, `/promotions`, `/welcome`).
- **Update the portal `README.md`** so it links the current address.

## 2. Search (P1)

- **JSON-LD:** add `Organization` (with the WhatsApp contact point and `sameAs` the YouTube channel), `WebSite` and `BreadcrumbList` on inner pages.
- **One OG image per app page** (4 exist). Add them for features, pricing and guides.
- **Guides** (`/guides/{slug}`), one per real search. Each is dated and signed by a real person, and ends with a link to the right app:
  - **Shelfie:** how to keep a shop's stock book, stop losing money to credit, run a supermarket till, EFRIS for small shops.
  - **Kuku Tally:** lay rate and how to improve it, feed cost per tray, vaccination schedule for layers and broilers, keeping farm records a bank accepts.
  - **Pledge & Budget:** how to plan a wedding budget in Uganda, running a wedding committee, tracking pledges, closing the books after the event.
- **Calculators as pages:** a wedding budget calculator, a poultry feed and profit calculator, and a shop margin calculator. Each offers "Do this in the app".
- **Video:** one short video per app on the owner's YouTube channel (Learn It With Muhindo, 23,000+ subscribers), embedded with a click-to-load facade.

## 3. Prices per module (P1, owner decision)

- `FAMILY_PLAN.md` leaves per-module prices and event limits undecided.
- Decide them, store them in the `Plan` table with entitlements per module, and enforce them through `Quotas`, for example:
  - the event limit for Pledge & Budget;
  - the number of batches or birds for Kuku Tally;
  - products, users and locations for Shelfie (exists).
- **The pricing page** shows each app's own prices, the bundle (Budget Pro, all modules), the per-day price, and the one-off copy (`/own`).
- **Pledge & Budget** is priced per event (one payment per wedding) as well as monthly, because events end.

## 4. The WhatsApp number on every surface (P0)

The number is **+256 783 204 665** (digits `256783204665`).

**One kernel reader:**

- add `App\Support\SupportContact` in the kernel, reading the platform settings with env fallbacks (`SUPPORT_WHATSAPP`, `SUPPORT_PHONE`, `SUPPORT_EMAIL`, `SUPPORT_HOURS`);
- the portal's `config/site.php` reads the same values, so there is one number, not two settings.

**Prefilled messages per context** live in `lang/*/support.php`, for example:

- "Hello Budget Pro, I am using Shelfie for :shop and need help with :screen."
- "Hello, I would like a demo of Kuku Tally."

**Never put a customer's name, an amount or a phone number in a message.**

**Surfaces:**

| Surface | Today | Build |
|---|---|---|
| Public site: header, floating button, footer, contact, every app page, pricing, demo, guides, error pages | Exists, hidden while `SITE_WHATSAPP` is empty | Set the value; add the closing line on each app page and guide |
| Portal admin: Help menu, onboarding wizard, plan and limit modals, the grace banner, error toasts | "Chat with support" exists in the menu | Add to onboarding, limits, grace, errors (with the reference id) |
| Classic admin: sign-in, `subscription-expired`, header | Uses `mail.from.address` only | Add the WhatsApp line |
| Emails from the platform | None | Footer on every email |
| Receipts and PDFs | The shop's own details | Optional "Powered by Budget Pro · WhatsApp …" line, on by default on SaaS, off on standalone |
| `GET /api/v1/meta` | No `support` block (the app expects one) | Add `support` with WhatsApp, phone, email, hours and templates; on standalone, the copy's own support first |
| The four apps | Hard-coded in `ContactUsScreen.dart` | Read `meta.support` (05 §5) |
| Store listings, YouTube, social profiles | n/a | Same number |

**Click tracking:** each link goes through `/go/whatsapp?c=…`, which records the click and redirects; the plain `wa.me` link stays as a fallback.

**No hard-coded number:** a CI scan in all three repos fails if the number appears outside config, env examples and docs.

## 5. The funnel (P1)

| Step | Build |
|---|---|
| Visit | App page answers the search; WhatsApp visible |
| Talk | One tap, page named in the message |
| Try | The one-click public demo exists. Add a demo farm and a demo wedding event, rebuilt on the same schedule as the demo shop (`PublicDemoBuilder`) |
| Start | Sign-up asks for the modules (exists) and the WhatsApp number of the owner |
| Welcome | Email plus WhatsApp welcome with one first action per module |
| Activate | Time to the first sale, the first daily card, or the first pledge, recorded |
| Nudge | Day 1, 3, 7 and 12 messages |
| Pay | Flutterwave card and mobile money (exists) |
| Grow | Add a module from inside any app (exists), message bundles, the one-off copy |

**Funnel events** (`whatsapp_click`, `demo_open`, `signup_done`, `first_action`, `paid`) show on the platform screens by app and source.
