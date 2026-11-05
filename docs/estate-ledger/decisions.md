# Estate Ledger: decisions

Every choice made for Estate Ledger, one per entry. Format: **id: decision.** *Why.* Alternatives. A decision changes only
through a new entry that names the one it replaces. Owner decisions are marked **(owner)**.

## Product and brand

**KR-01: Estate Ledger is a kernel module, portal screens, a flavor and a site, not a new stack.** Module `rentals`,
portal `/rentals` and `/my`, flavor `estate-ledger`, site `estate-ledger`. *Why:* tenancy, sign-in, roles, files, PDFs, plans,
billing, demo, sites and flavors exist and are tested; one deploy keeps the lockstep (the owner asked to reuse as
much as possible). *Alternatives:* a new Laravel app (a second backend, duplicated auth and billing); extending the
earlier estate system (single-tenant laravel-admin, no ledger; see 01-study).

**KR-02: The name.** First chosen as Keyroll, renamed by the owner on 2026-11-04 to **Estate Ledger – Rent & Tenants**
(short name Estate Ledger). Tagline "Every door, every tenant, every shilling."

**KR-03: Brand: graphite ink `#1E2B33`, brass `#B8862E`, warm paper `#FAF8F4`, square corners.** White on the ink
reads at 14.5:1. Brass is for the logo and small highlights only (3.3:1 on white: never text). The logo is a brass
key whose bow is a house outline, on a square ink tile. *Why:* in a rent app colour must mean money status (paid
green, due amber, overdue red, vacant grey); a neutral brand keeps those signals clear, and ink with brass reads
as keys, doors and trust. The owner asked for square-cornered, minimal widgets. *Alternatives:* brick terracotta
(clashes with "overdue" red, close to another app's burnt orange); navy (close to another app's indigo).

**KR-04: The module is exclusive.** An Estate Ledger account keeps only the rental books: no shop, no finance module.
*Why:* clean menus, its own plan line, its own brand; the same rule as the SACCO module. *Alternative:* rentals as
one more module beside a shop (menus and plans would mix).

**KR-05: Estate Ledger stands alone.** Nothing an Estate Ledger user sees (site, sign-in, `/rentals`, `/my`, emails, texts, PDFs,
the app and its store assets) names another app, says "by …", shows a family menu or another demo. A guard test
renders every Estate-Ledger-host page and fails on other app names. *Why:* the owner's rule for Akiba applies to every
app that is rebranded on its own ("rebrand everything accordingly").

## Money

**KR-06: Two modes: agency and owner.** An agency manages for landlords (commission, payouts, landlord statements);
an owner manages their own property (one hidden landlord "You", no commission). Chosen at setup; owner → agency is
allowed any time; agency → owner only with one landlord. *Why:* both are the market (agencies like the earlier
system's clients, and landlords who collect themselves).

**KR-07: Integer minor units, a balanced journal, charges and allocations (ADR 0010).** *Why:* exact money,
provable books, nothing edited. *Alternatives:* balances in columns updated by hooks (the earlier system; drifts);
ADR 0009's double-entry ledger (more machinery than an agency needs).

**KR-08: Landlords are on the cash basis.** A landlord earns when rent is collected, not when it is billed;
commission is on what is collected. *Why:* that is how agencies pay landlords, and the earlier system's reports
worked that way.

**KR-09: Commission: percent or flat per charge, from lease → property → landlord → settings.** Proportional on part
payments, exact once a charge is fully paid (04 §5.3). Fees beside rent are not commissionable unless ticked.

**KR-10: A payment settles the oldest charge first:** by due date, then deposit, opening balance, rent, fees,
utilities, one-offs, late fees last. *Why:* clears the deposit and rent before penalties; predictable for tenants.

**KR-11: Rent is billed in advance per period.** The period runs from the move-in day by default (anchor `start`);
an account can align to the 1st with a part-month first bill (anchor `first`). Cycles of 1, 3, 6 or 12 months. Bills
are raised `bill_days_before` (5) days ahead. *Why:* the earlier system's practice (bills a few days early) and the
market's (rent paid ahead, monthly or quarterly).

**KR-12: Money paid ahead is credit and applies itself to the next bills.** Screens show "Paid up to".

**KR-13: A deposit is the tenant's money, never income.** Held by the agency or the landlord (per property); at
move-out it settles what is owed, and the rest is refunded.

**KR-14: Late fees are off by default; when on, one per overdue rent charge** (flat or percent of what is left),
after grace days, kept by the agency or the landlord. No legal hard-blocks (the owner's rule for Akiba).

**KR-15: No edits or deletes of posted money; reversals with a reason.** Only the reverse permission may reverse.
A landlord's balance may go below zero after a reversal; screens say "the landlord owes you".

## People and access

**KR-16: Tenants and landlords sign in to their own portal, never as staff** (`rental_logins`, `/my`, tokens with
`rentals-portal`). *Why:* one business per staff login; a tenant can rent from several agencies.

**KR-17: Caretakers see only the properties they are assigned to;** a record outside the scope is a 404.

## App, messages, plans, demo

**KR-18: Messages are free links opened on the user's phone** (WhatsApp and SMS), with editable texts. The server
sends nothing unless platform messaging is configured and the account is entitled (`rentals_sms`). *Why:*
production messaging is off, and the pledge reminders already work this way.

**KR-19: Offline on the phone: payments, meter readings and maintenance requests** queue with a uuid; the rest needs
the network. *Why:* caretakers collect at the gate; money out (payouts, refunds, reversals) must not be posted twice.

**KR-20: Plans on their own line `estate-ledger`, counted in units** (04 §15). Prices are the owner's call.

**KR-21: Estate Ledger has its own demo agency** (Ntinda Property Managers (Demo)), per-role sign-ins plus a landlord and
a tenant, healed hourly, rebuilt every 72 hours. Never the shared family demo.

**KR-22: Moving in from another system is a first-class path:** CSV templates and a dry-run import of landlords,
properties, units, tenants, leases and opening balances. *Why:* the earlier system's clients must be able to move
without retyping years of tenants.

**KR-23: The web portal is light only** (the owner's rule) and Estate Ledger's pages draw square corners.

## Found while proving the backend

**KR-24: A move-out whose refund the cash cannot cover is refused whole, before anything is written.** The words say what
is short and the ways through: move money first, choose another account, or move out and refund later (`refund-deposit`,
`refund-credit`). *Why:* §5.10 keeps the cash guard for real overdrafts, and a person must not lose a half-done move-out to
it. *Alternative:* record the move-out and leave the refund owing automatically (a surprise for whoever chose to refund now).

**KR-25: Opening cash is entered per account through the journal, once** (cash +, equity +; `replace` corrects it). *Why:*
an agency has money before its first rent; the guard then protects real overdrafts only.

**KR-26: A payment that paid a deposit cannot be reversed once that deposit was refunded or used** (`deposit_gone`).
*Why:* the deposit account would go below zero with the money already gone. What the tenant then owes is a new bill.

**KR-27: A receipt is a snapshot written once when the payment is posted.** A reprint shows what it showed when issued; a
reversal adds a line and changes nothing else. *Why:* ADR 0010's claim, and a paper a tenant holds must match the paper
reprinted later.

**KR-28: A month closes, once, and what was issued stays.** Closing locks the month at `RentalLedger` and issues each landlord a numbered statement with a proof. Late money
is dated today and marked for its month. Only the account owner reopens, with a reason, and only the latest month; a correction makes a new version that says what it
replaces. It is on every plan. *Why:* an agency sends these papers to landlords, and books that can change under a sent paper cannot be trusted. *Alternative:* recomputing a
statement from the rows each time (ADR 0010 before this): a unit switched off or a name corrected would change a paper the landlord already holds.

**KR-29: Bill ahead is a choice beside KR-12, not a replacement.** Money above what is owed stays credit by default, and the till offers billing the next periods now, as one write
with real bills and their commission. A setting flips which is offered first. *Why:* some agencies pay landlords on money received, while KR-12's protection of the tenant stays the default.

**KR-30: An advance to a landlord needs a tick and a reason, and a second tick if the agency's own money does not cover it.** It is a warning that can be passed, never a refusal. The
landlord's balance goes below zero and clears as rent comes in. *Why:* agencies do this, and it should be written down, not hidden in a note.

**KR-31: A tenant who paid the landlord directly is a payment with no cash** (`paid_landlord`, journal `collected_direct`). The bill is settled and the landlord's balance falls by the commission.
It settles this landlord's bills only, leaves no credit, never pays a deposit, and is never offered to a phone. *Why:* otherwise staff invent a payment and a payout to record it. The agent still earns.

**KR-32: Money held back for a landlord is the agency's own percent, recorded when actually paid over.** Off by default. No law is stated and no tax is worked out by the system: it multiplies the
agency's typed percent by the rent the agency collected, as a figure the office can change. The screen says to confirm the rate with an accountant. It is an expense charged to the landlord, so
no new account is needed. *Alternative:* a held-but-unpaid liability: it would need a new column and rule (ADR 0010), for a rule nobody here can state.

**KR-33: A room the landlord or caretaker uses is its own unit status** (`in_use`, with who). Not vacant, not let, no rent lost, left out of occupancy and counted apart. A tenant moves in only once it is cleared.
*Why:* Leases refuse zero rent, so such a room otherwise reads as vacant and as rent lost.

