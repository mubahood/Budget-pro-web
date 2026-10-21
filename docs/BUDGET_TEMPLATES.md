# Budget templates, event settings and message templates

Pledge phase 1, October 2026. This covers the kernel (budget-pro) and the phone app (budget-pro-mobo, `lib/pledges`).
The web portal's `/events` area gets the same features in phase 2, through the same services.

## One source of truth

`resources/data/budget_templates.json` holds:

- the templates;
- the event types;
- the default pledge groups;
- the default message texts in English, Luganda and Kiswahili;
- approximate exchange rates for accounts that don't use UGX.

Every reader uses this one file:

| Reader | How it reads the file |
|---|---|
| Kernel | `App\Services\Budget\BudgetTemplates` |
| API | `GET /api/v1/budget-templates` |
| Demo | `PublicDemoWedding` |
| Phone | Bundles a byte-for-byte copy at `assets/pledges/budget_templates.json` for its first, offline run. It refreshes the copy from `GET /api/v1/budget-templates?full=1` using the ETag. |

### Changing a template

1. Edit the JSON file. Bump `version` (`YYYY.MM.n`).
2. Run `php artisan test --compact tests/Feature/Budget/BudgetTemplatesTest.php`. The test validates the file: unique keys, known event types and sizes, every item named with a unit, prices in whole shillings above 0, `per` at least 1, English text for every message kind, and only known placeholders.
3. Copy the file into the app:

   ```
   cp resources/data/budget_templates.json "${MOBILE_REPO:-/Users/mac/Desktop/github/budget-pro-mobo}/assets/pledges/"
   ```

   The kernel test `test_the_phone_app_bundles_the_same_file` fails until the two copies match. Set `MOBILE_REPO` if the app checkout lives somewhere else. The app's `test/pledges/pledge_templates_test.dart` validates the bundled copy as well.

Phones that already cached an older version pick up the new one with their next ETag check.

## Template format

```json
{"key": "kwanjula-buganda", "name": "…", "description": "…", "event_type": "introduction",
 "culture": ["baganda"], "religion": ["any"], "size": "medium", "guests": 300,
 "groups": ["Groom's family", "…"], "roles": [{"title": "Chairperson", "role": "chair"}],
 "categories": [{"name": "Food and drinks", "items": [
   {"name": "Buffet for the guests", "unit": "plate", "qty": 1, "per": 1, "price": 25000},
   {"name": "Sodas (crate of 24)", "unit": "crate", "qty": 1, "per": 12, "price": 22000},
   {"name": "Omutwalo (bride price envelope)", "unit": "envelope", "qty": 1, "price": 2000000}]}]}
```

### Quantities

The quantity of an item depends on whether it has `per`, for a guest count G:

| Item | Quantity |
|---|---|
| No `per` | `qty` (the item stays fixed) |
| With `per` | `ceil(G / per) × qty` |

`per` is the number of guests covered by each `qty`. Two examples of what scales:

- a buffet with `per: 1` gives one plate per guest;
- sodas with `per: 12` give two sodas a guest, in crates of 24.

### Totals and currency

- An item's total is `price × quantity`. Totals are always computed, never stored.
- In an account that doesn't use UGX, each unit price becomes `max(1, round(price / rates[currency]))`.
- `culture` and `religion` are tags. A template tagged `any` matches every filter value.
- `size` is one of `small`, `medium`, `large` or `luxury`.

## The templates (version 2026.10.1), totals at their own guest count

| Key | Template | Guests | Items | Total (UGX) |
|---|---|---:|---:|---:|
| kukyala-visitation | Kukyala (visit before the introduction) | 30 | 10 | 1,735,000 |
| kwanjula-buganda | Kwanjula (Buganda introduction) | 300 | 43 | 32,221,000 |
| kuhingira-ankole-kiga | Kuhingira (Ankole and Kiga give-away) | 400 | 29 | 39,177,000 |
| acholi-lango-traditional | Nyom (Acholi and Lango traditional marriage; also tagged Iteso) | 300 | 22 | 28,326,000 |
| catholic-wedding | Catholic church wedding | 300 | 33 | 41,271,000 |
| anglican-wedding | Anglican wedding (Church of Uganda) | 300 | 33 | 41,671,000 |
| pentecostal-wedding | Pentecostal (born-again) wedding, no alcohol | 250 | 30 | 33,416,000 |
| nikah-walima | Nikah and Walima (Muslim wedding) | 300 | 27 | 23,136,000 |
| civil-wedding | Civil wedding (registrar) | 30 | 9 | 4,110,000 |
| intro-and-wedding | Introduction and wedding together | 400 | 40 | 57,692,000 |
| village-wedding | Simple village wedding | 150 | 16 | 5,928,000 |
| kampala-hotel-wedding | Kampala hotel wedding (mid-range) | 250 | 22 | 51,707,000 |
| garden-wedding | Garden wedding | 300 | 31 | 52,411,000 |
| luxury-wedding | Luxury wedding | 500 | 25 | 191,426,000 |
| send-off-kasiki | Send-off party (kasiki) | 120 | 15 | 9,740,000 |
| graduation-party | Graduation party | 100 | 12 | 5,658,000 |
| fundraiser | Fundraiser (church, school fees or medical bill) | 150 | 9 | 13,575,000 |

### Where the 2026 prices come from

The prices were checked in October 2026. Most per-item figures come from two sources: HarusiHub's 2026 guide and the Palm Gardens guides. Both are run by vendors. The Daily Monitor article from 13 October 2026 and Jiji listings were used to check them. Kampala prices are used throughout. HarusiHub puts regional towns 20–40% below Kampala.

#### Pages that were read

- Daily Monitor, "The wedding pledge trap", 13 Oct 2026, https://www.monitor.co.ug/uganda/business/prosper/the-wedding-pledge-trap-5628700. It gives these figures:
  - a modest kwanjula costs UGX 20–35M;
  - outfits cost about 2M;
  - decor and tents cost 4–7M;
  - catering for 200–300 guests costs 6–10M;
  - photo and video cost 2.5M;
  - a garden wedding costs 40–80M;
  - hotel plates cost 120–180k.
- HarusiHub, "How Much Does a Wedding Cost in Uganda? (2026 Guide)", https://blog.harusihub.com/post/wedding-cost-uganda/. It gives kwanjula gifts, attire, catering, cake, music, transport and church lines, plus sample budgets of 13M, 42M and 104M.
- Palm Gardens:
  - Kwanjula guide, https://palmgardensug.com/the-complete-kwanjula-guide-in-uganda-costs-planning-venues-how-to-make-it-happen/: catering is 25–80k a guest, and about 300 guests come to 15–25M.
  - Wedding cost guide, https://palmgardensug.com/how-much-does-a-wedding-cost-in-uganda/: food is 20–35k a person.
  - Location guide, https://palmgardensug.com/wedding-location-guide-in-uganda-what-to-consider-where-to-go-and-how-much-it-costs/: Fairway charges 95–109k a guest, gardens cost 2–5M, and premium hotels cost 8–30M or more.
- Jiji wedding tents, https://jiji.ug/159-wedding-tents: a tent for 50–100 guests costs 150k, a 400-guest tent costs 6M, and kukyala decor costs 3–3.5M.
- URSB civil registration, https://ursb.go.ug/civil-registration. It says marriage registration has moved to NIRA.

#### Search results only (the pages were not opened)

- All Saints Cathedral wedding requirements, https://allsaintskampala.org/download/ASCK_WeddingRequirements.pdf: church fees total 900k.
- Watoto wedding guidelines 2024: the church fee is 600k.
- URSB registrar guidelines, https://intranet01.ursb.go.ug/wp-content/uploads/2017/05/Guidelines-Registrars.pdf: the civil marriage bundle costs 260k.
- Daily Monitor on meat prices, June 2026: a village cow costs 1.5–5M.
- Daily Monitor on the Acholi bride price by-law: bride price is capped at 5M, with 6 cows and 6 goats.
- Pulse Uganda on bride price in Ankole, Aug 2026.
- Owino.ug: a crate of 24 sodas costs 22,000.
- Daily Monitor and Abacus Vacations on car and coaster hire: a coaster bus is about USD 150 a day.

#### Our own estimates

No source was found for these. They are reasonable 2026 figures:

- chairs and tables;
- beer and wine;
- soap, paraffin and fruit;
- goats;
- fuel at about 5,200 a litre;
- invitations, programmes, security and ushers;
- accommodation;
- the imam's fee and a typical mahr;
- the totals for the kukyala, kuhingira, nikah, Acholi, Lango and Teso ceremonies, kasiki and graduation.

Our own data agrees with these sources. The local database's real events show:

- kwanjula and wedding pledges of UGX 50,000 to 2,000,000;
- budgets of 15–45M.

## Applying a template (merge rules)

The same rules apply on the web and API (`App\Services\Budget\TemplateApplier`) and on the phone (`lib/pledges/template_logic.dart`, which writes through SyncRepo and the outbox):

1. If the event already has a category with the same name (trimmed, any case), the template's items go into it. A second category with that name is never made.
2. If that category already has an item with the same name (trimmed, any case), the item is left as it is and reported as skipped. It is never overwritten and never duplicated. A line that appears twice in one plan is skipped the second time.
3. A new item gets:
   - the quantity and unit price from the reviewed plan;
   - nothing spent and not approved;
   - the details `unit: <unit>, from template <key>`.
4. Lines the person unticked are not added. A quantity of 0 or less, or a negative price, is refused.
5. The event's settings change as follows:
   - `event_type` is set only when the event has none;
   - `template_key` is always set;
   - `groups` are set only when the event has no list of its own;
   - `settings.guests` stores the guest count.
6. Nothing is ever deleted.

Who may apply a template:

| Where | Who |
|---|---|
| Web and API | Full access, or the event's chair (`BudgetAccess::canManageEvent`) |
| Phone | Only `manage_budget`, because sync refuses budget items from anyone else |

While a template is applied, the "budget item changed" email is muted (`BudgetItem::$notify`), so the team gets no email per item.

## Event settings (budget_programs columns, all optional)

The migration `2026_10_21_520001_add_event_settings_to_budget_programs` adds these columns. All of them are optional.

| Column | Holds |
|---|---|
| `event_type` | A key from `event_types`: wedding, introduction, giveaway, nikah, civil, send_off, visitation, graduation, fundraiser, other. |
| `template_key` | The template last applied. |
| `venue` | Free text, up to 191 characters. |
| `groups` (existing column, unused until now) | A JSON list of group names. An older value typed as text (one per line, or comma-separated) is still read. Empty means the default list. |
| `settings` | JSON: `{v, messages: {kind: text}, message_lang (en, lg or sw), presets: [amounts], currency_display, guests, meeting: {when, where}}`. Keys this version doesn't know are kept. |
| `payment_details` | JSON: `{mtn, mtn_name, airtel, airtel_name, bank, bank_account, bank_account_name, treasurer, treasurer_phone, note}`. Phone numbers are stored in E.164. |

All of these columns are text, so sync sends them to phones as strings. `BudgetProgram` cleans them on every save (`EventSettings::normalize`):

- bad JSON becomes empty;
- groups become a clean JSON list;
- phone numbers become E.164;
- unknown payment keys are dropped.

A value is never refused. Older apps never send these columns, so their edits leave the columns as they are.

### Pledge groups and the old keys

A pledge stores `category_id`, which is `EventBook::groupKey(label)`:

- a label from the old list maps to its old key: Friends is stored as `Friend`, and Old boys and girls as `OBs_and_OGs`;
- anything else is stored as it was typed.

Groups used by pledges but missing from the list still appear in filters and printouts (`EventBook::groups`).

### Who may change what

| What | Who |
|---|---|
| Settings (type, venue, groups, settings) | The chair, the owner, or `manage_budget` |
| Payment details | Those, and also the treasurer |

The rules are the same everywhere:

- **API:** `PUT /api/v1/budget-programs/{id}/settings`.
- **Sync:** `BudgetAccess::allowsSettingsSyncOp` lets a `budget_programs` update through only when it carries nothing but those fields. A new event, a delete, or a change of name or date still needs `manage_budget`.

## Messages

There are five kinds: reminder, thanks, confirmation, invitation and meeting.

1. An event may store its own text for each kind.
2. If it has none, the default in its `message_lang` from the JSON file is used.
3. If that language has no text either, English is used.

Placeholders:

- `{name}`, `{amount}`/`{pledged}`, `{paid}`, `{balance}`;
- `{payment}` and `{how}` (in confirmations);
- `{event}`, `{date}`, `{days_left}`, `{venue}`;
- `{treasurer}`, `{treasurer_phone}`;
- `{payment_number}` (the MTN number, or the Airtel one);
- `{payment_details}` (several lines);
- `{meeting_when}`, `{meeting_where}`.

Rendering works like this (`MessageTemplates::render`, and `PledgeMessages.render` on the phone):

- known placeholders are replaced;
- unknown ones stay as typed;
- a line whose placeholders all came out empty is dropped, so "Pay to: …" disappears when no number is set;
- three or more newlines become two.

Messages always open in WhatsApp or SMS on the person's own phone. The server never sends them.

The Luganda texts are a first draft. A native speaker should review them before marketing uses them.

## The public demo (Fresh Corner Market, now in UGX)

The demo is now set in Kampala:

- `config/demo.php`: UGX, UG, Africa/Kampala.
- `database/data/public_demo.php`: Ugandan supplier, customer and team names, made-up +256 numbers, and every price in rounded shillings.
- `PublicDemoBuilder`: the float, safe drops, promotions, gift cards, freight, wages and loyalty settings are in UGX. VAT is 18% and included in prices. Alcohol is for ages 18 and up.
- `PublicDemoBuilder::BUILD = 2`: a live demo built by an older version (in dollars, with one wedding) is rebuilt on the next hourly run instead of healed.

`PublicDemoWedding` (data in `database/data/demo_wedding.php`) builds three events for Brian Tumusiime and Grace Nakato. Days are counted from the build date.

| Event | When | State | Template | Pledges |
|---|---|---|---|---|
| Kukyala | 6 weeks ago | Closed. Everything is paid and paid out. | kukyala-visitation | 15 |
| Kwanjula | In 5 weeks | Open. Messages are in Luganda. | kwanjula-buganda | about 49 |
| Wedding | In 11 weeks | Open. | anglican-wedding | about 114, in 8 groups |

Across the three events, the demo shows:

- payments over the past weeks by cash, MTN, Airtel and bank, some in parts;
- one payment recorded on the wrong pledge, reversed with a reason, and recorded on the right pledge;
- one budget item over budget;
- the committee: the demo account is chair, the manager is treasurer, and two members of the team sit on it;
- payment details, presets and a committee meeting;
- a custom reminder with `{days_left}`.

The hourly heal handles visitors' changes:

- an event that was switched off is put back, and the Kukyala stays closed;
- if one of the three events was deleted outright, all three are rebuilt, so their books always agree.

`php artisan demo:family` (local accounts and the website pictures) keeps its older 60-pledge wedding, because the phone screenshot fixture repeats that wedding line for line.
