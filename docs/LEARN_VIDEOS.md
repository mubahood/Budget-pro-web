# Learn: training videos for every app

Learn is a set of short videos that teach people how to use an app. It is built once, on this server, for every app. Each lesson says which apps show it. You do not change code to add a lesson. You add it in the admin, or you import it from a JSON file.

The Learn screen in each phone app lists the lessons for that app. A "How this works" link on a screen opens the lessons for that screen.

This design follows School Dynamics' training videos (`schools-modern`: `app/Services/Training`, `api/V2/TrainingController`). See "What changed from School Dynamics" at the end.

## The parts

| Part | Where |
|---|---|
| Tables | `database/migrations/2026_11_28_200001_create_learn_tables.php` |
| Admin menu entry | `database/migrations/2026_11_28_200002_learn_videos_menu.php` |
| Settings | `config/learn.php` |
| Models | `app/Models/Learn/` (`LearnSection`, `LearnLesson`, `LearnLessonApp`, `LearnProgress`) |
| Rules: who sees what, progress, "new" | `app/Services/Learn/Learn.php` |
| YouTube link reader and oEmbed title | `app/Services/Learn/YouTube.php` |
| Uploaded files (chunked) | `app/Services/Learn/LearnUploads.php` |
| JSON import | `app/Services/Learn/LearnImporter.php`, `php artisan learn:import` |
| Placeholder lessons for a local copy | `database/seeders/LearnPlaceholderSeeder.php` |
| Phone and web API | `app/Http/Controllers/Api/V1/LearnController.php` |
| Admin page | `app/Admin/Controllers/LearnVideoController.php`, `resources/views/admin/learn-videos.blade.php`, `learn-video.blade.php` |
| Tests | `tests/Feature/Learn/LearnApiTest.php`, `LearnAdminTest.php` |

## Data model

**`learn_sections`** are the headings on the Learn screen, such as "Getting started". All apps share them. A section with no lessons for an app is not shown in that app.
- Fields: `slug` (unique), `title`, `description`, `sort`.

**`learn_lessons`** holds one video each.

| Field | Meaning |
|---|---|
| `key` | A stable name used by the import, e.g. `kin.contributions.record`. It is empty for lessons made in the admin. |
| `section_id` | Its heading. If empty, the lesson goes under "More". |
| `title`, `summary`, `description` | Summary is one line under the title. Description shows under the player. |
| `all_apps` | true: every app shows it, including apps added later. |
| `areas` | A JSON list of screen keys, used by "How this works" links, e.g. `["contributions"]`. |
| `audience` | `public` (anyone, signed out too), `everyone` (signed in), `officers`, or `members`. |
| `roles` | Optional JSON list of role keys. The person must have one of these roles. |
| `permissions` | Optional JSON list of permission keys. The person must hold one of them. |
| `language` | `en`, `sw` or `lg`. With `?lang=sw` the phone gets the Swahili lessons and the English ones. |
| `source_type` | `youtube`, `file` (uploaded here), or `url` (a link to an .mp4 somewhere else). |
| `youtube_id` | Only the 11-character id is kept. |
| `video_url` | The link, for `source_type = url`. |
| `file_path`, `file_size` | The uploaded file, for `source_type = file`. |
| `duration_seconds` | If empty, it is filled from the first progress report. |
| `thumbnail_url`, `thumbnail_path` | Optional picture. If both are empty, a YouTube lesson uses YouTube's picture. |
| `published`, `published_at` | `published_at` is set the first time the lesson is published. It drives "new". |
| `featured` | Shown at the top of Learn. The first featured lesson the person has not finished wins. |
| `sort` | Order inside its section. |

**`learn_lesson_apps`** holds `(lesson_id, app)`, one row for each app a lesson is for. The app is an `AppFamily` slug (`family-roots`, `akiba-ledger`, `shelfie`, ...). A lesson with `all_apps = true` has no rows here.

**`learn_progress`** has one row per person per lesson. School Dynamics kept one row per sitting.

| Field | Meaning |
|---|---|
| `position_seconds` | Where they stopped. The player resumes here. |
| `furthest_seconds` | The furthest point they reached. |
| `watched_seconds` | Time actually played. Each report can add at most 60 seconds (`learn.max_step`). |
| `views` | Number of sittings started. |
| `completed_at` | Set when they reach 90% (`learn.finished_at`) or the player says the video ended. |
| `company_id`, `app`, `first_seen_at`, `last_seen_at`, `duration_seconds` | Context for the admin's numbers. |

**`learn_seen`** has one `(user_id, app, seen_at)` row: the last time the person opened Learn in that app.

## Who sees a lesson

A lesson shows in an app only when all three of these are true:

1. It is published.
2. It is for all apps, or it names that app.
3. Its audience lets the person in:
   - `public`: anyone, signed in or not.
   - `everyone`: anyone signed in.
   - `officers`: anyone signed in whose role is not a member role.
   - `members`: the member roles, `viewer`, `kin_member` and `unity_member` (`learn.member_roles`).

The roles come from `App\Services\Team\Permissions::roleOf`. The owner counts as an officer.

`roles` and `permissions` narrow a lesson further. For example, `audience: officers` with `roles: ["kin_treasurer"]` reaches treasurers only.

Signed out, the API returns only `public` lessons. Use `public` for "what is this app" videos that are safe to show before sign-in.

## Which app is asking

The phone sends its app in the **`X-App`** header. `ApiService` adds it to every request with `AppFlavor.current.slug`. A browser or a script can use `?app=` instead.

Older phone names are accepted, through `AppFamily::slug`: `kin` is `family-roots`, `keyroll` is `estate-ledger`, `akiba` is `akiba-ledger`.

A missing or unknown app gets a **422**.

To let lessons target an app before it is added to `AppFamily::APPS`, add it to `config('learn.extra_apps')` as `slug => name`.

## API contract

All responses use the usual envelope: `{"code": 1, "message": "...", "data": ...}`. Send `X-App` on every call.

### GET /api/v1/learn

Lists the lessons for the app, grouped by section. The bearer token is optional. Throttle: 60 a minute.

| Query | Meaning |
|---|---|
| `area=contributions` | Only lessons for that screen ("How this works"). |
| `q=member` | Search the title, summary and description. |
| `lang=sw` | Lessons in that language, plus the English ones. |

```json
{
  "code": 1, "message": "Lessons.",
  "data": {
    "app": "family-roots",
    "signed_in": true,
    "last_seen_at": "2026-11-27T09:12:00+03:00",
    "new_count": 1,
    "featured": { "id": 12, "title": "Record a contribution", "...": "same shape as a lesson below" },
    "sections": [
      {
        "key": "getting-started", "title": "Getting started", "description": null,
        "lessons": [
          {
            "id": 12,
            "title": "Record a contribution",
            "summary": "From the Contributions tab, in under a minute.",
            "section": "getting-started",
            "areas": ["contributions"],
            "audience": "everyone",
            "language": "en",
            "duration_seconds": 95,
            "length": "1:35",
            "thumbnail_url": "https://i.ytimg.com/vi/XXXXXXXXXXX/hqdefault.jpg",
            "source": {
              "type": "youtube",
              "youtube_id": "XXXXXXXXXXX",
              "url": null,
              "watch_url": "https://www.youtube.com/watch?v=XXXXXXXXXXX"
            },
            "featured": true,
            "published_at": "2026-11-28T10:00:00+03:00",
            "is_new": true,
            "progress": null
          }
        ]
      }
    ],
    "count": 4,
    "completed": 1
  }
}
```

How some fields are filled:
- `progress` is `null` until the person plays the lesson. After that it is `{"position": 92, "furthest": 92, "watched_seconds": 100, "completed": true, "last_watched_at": "..."}`.
- For `file` and `url` lessons, `source.url` is the .mp4 to play and `youtube_id` is null.
- `is_new` is true when the lesson was published after `last_seen_at` and the person has not started it. A person who has never opened Learn sees lessons from the last 30 days as new.
- Lessons with no section come last, under `"key": "more"`.

### GET /api/v1/learn/{id}

Returns one lesson, with its description and its neighbours in screen order. The token is optional. If the lesson is not visible to this person in this app, the answer is **404**.

```json
{ "code": 1, "message": "Lesson.",
  "data": { "lesson": { "...": "as above", "description": "Longer text" }, "previous_id": 11, "next_id": 13 } }
```

### POST /api/v1/learn/{id}/progress

Needs a token (`auth:sanctum`, `api.tenant`). Throttle: 120 a minute.

```json
{ "position": 42, "duration": 95, "watched": 15, "started": true, "completed": false }
```

| Field | Rule |
|---|---|
| `position` | Required. |
| `duration` | Optional. It teaches the server the length. |
| `watched` | Seconds played since the last report. Capped at 60. |
| `started` | Send `true` on the first report of a sitting. It counts a view. |
| `completed` | Send `true` when the player ends. |

The answer is `{"lesson_id": 12, "progress": {...}}`. If the lesson is not visible here, the answer is **404**.

The phone reports every 15 seconds while playing, and again on pause, at the end and on leaving. Reports made offline are queued and sent later.

### POST /api/v1/learn/seen

Needs a token. Send it when the Learn screen opens. Lessons published before this moment are no longer "new".

The answer is `{"last_seen_at": "..."}`.

## Adding a lesson

1. In the admin, open **Admin › Learn videos**. Only platform admins see it, and only on the SaaS server. It is a 404 on a standalone copy and a 403 for shop users.
2. Press **Add a lesson**.
3. Paste a YouTube link and press **Fill title and picture**. The title and picture come from YouTube's oEmbed, without a key.
   - For a file of your own, choose "A file uploaded here", save, then upload the file under **Files**. It goes up in 2 MB chunks, so the host's upload limit never matters.
   - It is stored in public storage (`admin` disk, `learn-videos/{id}/`), like the promo videos.
4. Tick the apps, or **All apps**. Pick or type a section, the audience, any area keys, and the language.
5. Tick **Published**, or save as a draft and press **Publish** in the list later.
6. Order the lessons in the list: drag a row by its handle, or use the arrows. The section arrows order the sections.

The list filters by app ("Show: ..."). For each lesson it shows:
- **Views**: sittings started.
- **People**: people who started it.
- **Done**: people who watched 90%.
- **Minutes**: minutes watched.

Write the words for the app that shows the lesson. A lesson for several apps must not name any one of them. Family Roots and Akiba Ledger never name another app.

### Import

`php artisan learn:import lessons.json [--publish] [--dry-run]`

Lessons are matched by `key`, so you can run the same file again after changing it. Lessons with errors are skipped and listed.

```json
{
  "sections": [ { "slug": "getting-started", "title": "Getting started", "sort": 1 } ],
  "lessons": [
    {
      "key": "kin.contributions.record",
      "section": "getting-started",
      "title": "Record a contribution",
      "summary": "From the Contributions tab.",
      "apps": ["family-roots"],
      "areas": ["contributions"],
      "audience": "everyone",
      "roles": [],
      "language": "en",
      "youtube": "https://youtu.be/XXXXXXXXXXX",
      "duration": 95,
      "featured": true,
      "published": false
    }
  ]
}
```

Notes on the fields:
- `apps` is `"all"` or a list of slugs. Older names such as `kin` work too.
- Use `video_url` instead of `youtube` for a file hosted somewhere else.
- `published` is kept as it is in the database unless the file sets it or you pass `--publish`.

### Placeholders on a local copy

`php artisan db:seed --class=LearnPlaceholderSeeder` adds four "[Placeholder]" lessons. They are unpublished and point at no real video: the id is all zeros. The seeder does nothing outside the `local` and `testing` environments.

## Adding Learn to a new app

On the server, add the app to `App\Support\AppFamily::APPS`, or to `config('learn.extra_apps')`. Nothing else changes. Lessons marked "All apps" appear in it at once.

In the phone app (`budget-pro-mobo`, see `docs/LEARN.md` there):
1. The flavour's slug goes out as `X-App` on its own. Check that `AppFlavor.slug` maps to the server slug through `AppFamily::slug`, or add an alias there.
2. Learn is already in the shared **More** tab ("Learn how to use {app}"), so every flavour has it.

On the web: read `GET /api/v1/learn?app=<slug>` and play `source.youtube_id` through `https://www.youtube-nocookie.com/embed/<id>`, or play `source.url` in a `<video>` tag.

## Adding a "How this works" link

1. Choose an area key for the screen: short, lowercase, and named after what the screen does, e.g. `contributions`, `gatherings`, `till`, `loans`.
2. On the phone, put `LearnHelpLink(area: 'contributions')` on the screen. The phone reads this app's whole lesson list once (`GET /api/v1/learn`, shared by every link, the home card and the More badge) and picks the lessons whose `areas` hold the key. It shows nothing when there is no lesson for that area, so you can add the link before the video exists.
3. In the admin, put the key in the lesson's "Screens it explains".

Area keys in use:

| Key | Screen |
|---|---|
| `contributions` | Family Roots: the Contributions tab (`KinDuesTab`). |
| `gatherings` | Family Roots: gatherings. |
| `family` | Family Roots: the people list. |
| `members` | UnityDesk: members. Akiba Ledger: members. |
| `dues` | UnityDesk: dues. |
| `meetings` | UnityDesk: meetings. |
| `welfare` | UnityDesk: welfare. |
| `voice` | UnityDesk: ballots and noticeboard. |
| `tree` | UnityDesk: the structure tree. |
| `pledges` | Pledge & Budget: the pledge book. |
| `payments` | Pledge & Budget: payments. |
| `spending` | Pledge & Budget: spending. |
| `loans` | Akiba Ledger: loans. |
| `receipts` | Akiba Ledger: receive money. |
| `tenants` | Estate Ledger: tenants. |
| `bills` | Estate Ledger: arrears and bills. |
| `properties` | Estate Ledger: properties. |
| `daily-record` | Kuku Tally: the daily card. |
| `sales` | Kuku Tally: sales. |
| `products` | Shop apps: products. |
| `expenses` | Shop apps: money records. |

Lessons belong to apps, so one key (`members`) can mean a different screen in each app.

Add a row here when you add a link.

## Push and notification links

A notification whose `data.link` (or `link`/`route`) is `learn/{id}` opens that lesson, and `learn` opens the Learn screen. See `LearnLinks` in the phone app. This works from the in-app notification list. No FCM tap routing exists yet.

## Tests

`php artisan test tests/Feature/Learn` covers:
- App scoping, including all-apps lessons, two-app lessons, the `kin` alias, and 422 without an app.
- Drafts hidden.
- Only public lessons when signed out.
- Officers, members and role-narrowed lessons.
- Areas, featured, progress (cap, resume, 90% completion, views), 404 for another app's lesson, 401 when signed out, and "new" cleared by `seen`.
- Admin: platform admins only, a 403 for shop owners, a 404 on a standalone copy, the oEmbed fill, "an app is required", publish, up/down and drag order, and the chunked upload.
- Import: dry run, add, update by key, and a bad app skipped.

The tables must exist in `budget_pro_test`: `DB_DATABASE=budget_pro_test php artisan migrate`.

## What changed from School Dynamics

Kept:
- Sections, storing only the YouTube id, the oEmbed title fill, the 60-second cap per report, finishing at 90%, the cached list on the phone, the offline progress queue, resume a few seconds back, the privacy-enhanced embed, and the views and completion numbers.

Changed or added:
- **App scoping.** One server serves many apps. A lesson targets one app, several apps, or all apps.
- **Audience per lesson**, not per section. It is by role and permission, not by user type, and a lesson can be public for people who are signed out.
- **Areas**, so a screen can link to its own lessons ("How this works").
- **"New since you last looked"**, featured lessons, a language per lesson, uploaded files and plain links besides YouTube, a single-lesson GET, and `learn/{id}` links.
- **One progress row per person per lesson**, not one row per sitting. The table stays small and the admin's numbers are simple sums.
