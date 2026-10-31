# allomusic backend (PHP 8.1+ / MySQL)

Admin panel and mobile API for the allomusic app, meant to live at
`https://allo.instadl.site` on Hostinger shared hosting.

## What is here

| Path | Purpose |
| --- | --- |
| `install.php` | Creates tables and the first admin. **Delete after setup.** |
| `admin/upgrade.php` | Applies new schema versions to an existing install. |
| `admin/` | Session-authenticated control panel. |
| `api/index.php` | JSON API used by the Android app. |
| `includes/` | Bootstrap, PDO, auth, CSRF, rate limiting, layout. |
| `sql/schema.sql` | Full schema, safe to re-run. |
| `uploads/icons/` | Uploaded artwork (PHP execution disabled by `.htaccess`). |
| `cron/cleanup.php` | Purges expired tokens and old login attempts. |

## Hostinger setup

1. **Subdomain.** hPanel → Domains → Subdomains → create `allo.instadl.site`,
   document root `public_html/allo` (or any folder you can point at).
2. **Database.** hPanel → Databases → MySQL Databases. Create a database and a
   user, and note the four values. Hostinger usernames are prefixed, e.g.
   `u123456789_allo`.
3. **Upload.** Upload the *contents of this `allomusic-panel/` folder* into the
   subdomain's document root (not the folder itself). Keep the paths:
   `/admin/...`, `/api/index.php`, `/uploads/...`.
4. **Configure.** Copy `config.sample.php` to `config.php` and fill in:
   - `db.host` / `db.name` / `db.user` / `db.pass` from step 2
   - `app.base_url` = `https://allo.instadl.site`
   - `security.debug` = `false`
5. **Permissions.** `uploads/icons/` must be writable by PHP (usually `755`;
   use `775` if the host runs PHP as a different user).
6. **Install.** Visit `https://allo.instadl.site/install.php`, create the owner
   account, then **delete `install.php`**.
7. **Upgrade an existing install.** Upload the new backend files, then open
   **Admin → DB Upgrade** and press *Apply schema upgrade*. It is idempotent and
   adds new tables plus `events.art_url` without touching existing rows.
8. **Cron (optional).** hPanel → Advanced → Cron Jobs, daily:
   `php /home/USER/domains/allo.instadl.site/public_html/allo/cron/cleanup.php`
9. **HTTPS.** Enable the free SSL certificate for the subdomain and force HTTPS.

## Panel

- `https://allo.instadl.site/admin/` — sign in with the owner account.
- **Users** — search, ban/unban, revoke sessions, reset password, inspect every
  synced data namespace, delete a user and all their data.
- **App Settings** — app name, maintenance mode/message, minimum and latest
  version codes, update URL, forced update, announcement, feature flags JSON,
  and the active home-screen icon set.
- **App Icon** — switch between the icon sets compiled into the APK, and upload
  custom artwork used as the in-app brand image.
- **Notices** — broadcast or per-user messages, pulled by the app.
- **Community** — latest comments, like/dislike/bookmark/report counts, hide,
  restore or delete comments.
- **Audit Log** — every admin action with IP and timestamp.
- **Listening signals** — the user page shows 230+ derived signals (plays,
  completion/skip/like rates, per-hour and per-weekday buckets, top
  genres/artists/albums/tracks, streaks, device/app context) plus the most
  recent raw events, plus the first-run setup profile (age band, regions,
  artists and favourite songs).

### Icon limitation (important)

Android launcher icons are manifest components. The APK ships one
`activity-alias` per icon set, and the panel can only enable one of those. A
**newly uploaded image cannot become a home-screen icon until a new APK is
built**; the upload is stored and served as the in-app logo/preview. To add a
new launcher icon, drop the artwork into the app's `icon/` folder and rebuild
with that image added as another mipmap + alias.

## API

Base: `https://allo.instadl.site/api/index.php?action=...`

| Action | Method | Auth | Body / query |
| --- | --- | --- | --- |
| `health` | GET | no | — |
| `config` | GET | no | — |
| `register` | POST | no | `email, password, name, device_id, device_name, sdk, app_version`, optional `preferences` |
| `login` | POST | no | `email, password, device_id, ...` |
| `logout` | POST | bearer | — |
| `me` | GET | bearer | — |
| `password` | POST | bearer | `current, next` |
| `delete_account` | POST | bearer | — |
| `device` | POST | bearer | `device_id, device_name, sdk, app_version` |
| `data` | GET | bearer | `ns` (optional) |
| `data` | POST | bearer | `ns, json` (string or object, max 1 MB) |
| `data_delete` | POST | bearer | `ns` |
| `notifications` | GET | bearer | — |
| `event` | POST | bearer | `events` array (max 100): `type, track_id, title, artist, album, art_url, genre, duration_ms, listen_ms, ts` |
| `signals` | GET | bearer | — |
| `preferences` | GET | bearer | — |
| `preferences` | POST | bearer | `preferences` object (`age, gender, listening_time, notifications, regions, artists, songs`) |
| `history` | GET | bearer | `days` (1–180, default 30), `limit` (1–500) |
| `history_clear` | POST | bearer | `days` |
| `profile_stats` | GET | bearer | 30-day listening + social counters |
| `reaction` | POST | bearer | `song_key, action` (`like`/`dislike`/`clear`), optional `title, artist, art_url` |
| `reactions` | GET | bearer | `value` (`like`/`dislike`) |
| `bookmark` | POST | bearer | `song_key, action` (`add`/`remove`/`toggle`), optional metadata |
| `bookmarks` | GET | bearer | — |
| `song` | GET | bearer | `song_key` |
| `comments` | GET/POST | bearer | GET `song_key, limit`; POST `song_key, body, parent_id?` + optional metadata |
| `comment_delete` | POST | bearer | `id` |
| `comment_like` | POST | bearer | `id, action` (`like`/`unlike`) |
| `comment_report` | POST | bearer | `id, reason` |
| `my_comments` | GET | bearer | — |
| `trending` | GET | bearer | `days` (default 7) |
| `search_songs` | GET | bearer | `q` |
| `public_profile` | GET | bearer | `id` |
| `follow` | POST | bearer | `user_id, action` (`follow`/`unfollow`) |
| `following` / `followers` | GET | bearer | — |
| `artist_follow` | POST | bearer | `artist, action` (`follow`/`unfollow`) |
| `artist_follows` | GET | bearer | — |
| `notifications_read` | POST | bearer | `id` or `all: true` |
| `notifications_clear` | POST | bearer | — |

Auth header: `Authorization: Bearer <token>` (`X-Allo-Token` also accepted).

Responses: `{"ok":true,"data":...}` or
`{"ok":false,"error":{"code":"...","message":"..."}}`.

Tokens are random 32-byte values stored as SHA-256 hashes, valid 90 days, and
revoked on logout, ban, password change and account deletion.

## Security notes

- Passwords use `password_hash()` (bcrypt/argon as configured by PHP).
- All SQL is prepared (`PDO::ATTR_EMULATE_PREPARES = false`).
- Admin sessions: HttpOnly, SameSite=Lax, Secure on HTTPS, CSRF token on every
  POST, login rate limiting (5 failures / 15 minutes per email+IP).
- `config.php`, `includes/`, `sql/` and `uploads/*.php` are blocked by
  `.htaccess`.
- Never keep `install.php` after setup and never commit `config.php`.
