# 05. The four apps (`Desktop/github/budget-pro-mobo`)

**Goal:** four apps on Google Play, built from one Flutter codebase, each showing only its module:

- **Budget Pro** (`com.budget.dynamics`, already published);
- **Kuku Tally**;
- **Shelfie**;
- **Pledge & Budget**.

They are safe on a lost phone, report their crashes, receive push notifications, and get help from the server's support details, never hard-coded ones.

**What exists and stays:**

- the four flavors (`lib/core/app_flavor.dart`, `docs/FLAVORS.md`);
- the sync engine (`lib/sync/*`) with v2 push and pull;
- secure session, PIN lock and diagnostics;
- the Bluetooth thermal printer;
- English, Swahili and Luganda strings;
- 79 test files and the screenshot harness;
- the excellence plan (`docs/MOBILE_EXCELLENCE_PLAN.md`), almost complete.

---

## 1. Make the code safe (P0)

- **Create the git remote** (private) and push. Today the app's 22 commits exist on one machine.
- **Add CI:**
  - `flutter analyze`, `flutter test`, and a release build of each of the four flavors on every push;
  - the signing key comes from CI secrets, never the repo (`android/key.properties` stays ignored).
- **Rename the package** from `flutter_ui` to `budget_pro` in `pubspec.yaml` (internal only; the application IDs do not change).

## 2. Release the four apps (P0)

- **Confirm the three new application IDs** before their first upload, since they can never change afterwards:
  - `com.budgetpro.kukutally`
  - `com.budgetpro.shelfie`
  - `com.budgetpro.pledgeandbudget`

  Record them in `docs/decisions.md` (owner decision).
- **One store listing per app:**
  - its own name, short description, full description written for its user, screenshots from the harness per flavor, icon and feature graphic;
  - the privacy policy URL;
  - a true Data safety form;
  - the support email and phone (06 §4).
- **Tracks:** internal testing, then closed testing with real users of each module (a shop, a farm, a wedding committee), then production.
- **Keep one version** per release across the four flavors. Write a `CHANGELOG.md` and tag releases. Replace the stale `GOOGLE_PLAY_SUBMISSION.md` (it still says 1.0.0+1) with a current checklist.
- **The store links go into `config/apps.php`** on the portal, and the app pages' "Get the app" buttons go live.

## 3. Push notifications (P1)

- **Server:** an FCM sender in the kernel behind the messaging service, with device tokens (devices are registered already) and categories per module that users can switch on or off:
  - shop: low stock, a debt paid, shift variance;
  - poultry: vaccine due, lay rate dropped;
  - pledges: payment received, event countdown;
  - chat: a new message.
- **App:**
  - `firebase_messaging`, with notification categories per flavor;
  - a tap opens the exact record through a deep link;
  - App Links for `https://budget-dynamics.schooldynamics.ug/a/...` (and the standalone server's own address).

## 4. Crash reports and diagnostics (P1)

- `sentry_flutter` (or Firebase Crashlytics), with personal data scrubbed (names, phones, amounts), tagged by flavor and app version.
- **Diagnostics "Send to support on WhatsApp"** works once the server sends `meta.support` (06 §4). Today it falls back to copying.

## 5. Support from the server, not the code (P0)

- Remove the hard-coded email and phone in `lib/screens/Common/ContactUsScreen.dart:11-12`. Read `meta.support`: WhatsApp, phone, email, hours and templates.
- On a standalone server, show the support contact of that copy (the buyer's partner or own IT, then ours).
- A help card on the account page, the sign-in and the landing of each flavor.

## 6. Finish the excellence plan (P1)

- **C3:** move the remaining lists to `AppListShell` (reorder, expiring, shelf labels, stock requests, transfers).
- **E6:** payment reversal, once the server route exists (04 §1).
- **Replace `flutx` 0.2.0-rc.2** (an abandoned pre-release) with the app's own widgets, and remove the dependency.
- **The legacy `lib/screens/*` (35 files)** used by `budget_management`: move pledges and budget into a proper module folder (`lib/pledges/`), following the poultry module's structure (data, domain, services, screens), with sync and tests.

## 7. Each app fits its user (P1)

- **Kuku Tally:**
  - the daily card is the home screen;
  - big buttons for eggs, feed, deaths;
  - works fully offline;
  - the PDF report for the bank or the SACCO (04 §5).
- **Shelfie:**
  - the till is the home screen;
  - a Bluetooth printer and barcode scanner;
  - the debts book one tap away.
- **Pledge & Budget:**
  - the event countdown and "Record a payment" on the home screen;
  - reminders and the committee share (04 §4);
  - a warmer look within the same design tokens.
- **Budget Pro:** the full menu, with module switching for accounts that have several.
- **For all four:** sign-up inside each app sets its module (exists). A user who opens an app for a module they don't have sees "Add this to your account" (the endpoint exists).

## 8. Safe on a lost phone (P1)

- Encrypt the local database (SQLCipher), with the key in secure storage.
- Remote sign-out from the web wipes local data at the next contact and keeps unsent work exportable for the owner.
- Biometric unlock as an alternative to the PIN.
