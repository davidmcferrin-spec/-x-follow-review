# X follow review

A local queue for accounts you follow on X. You look at each profile yourself and record a decision. The tool does not call the X API, does not follow or unfollow anyone, and does not drive the browser. Unfollowing on the public account, or following again from a private account, is something you do by hand, usually in a second browser that is already logged into that account.

## Run

From this directory:

```bash
php -S localhost:8000
```

Open:

```text
http://localhost:8000/dashboard.html
```

`following.json` is created on the first import. It is gitignored, along with `actions.jsonl`. `following.example.json` is only a shape sample with two fake accounts. Do not treat it as a real export.

## Extension

1. Chrome or Edge: Extensions, enable developer mode, Load unpacked, choose the `extension/` folder.
2. Log into X and open your Following page: `https://x.com/<you>/following` (twitter.com works too).
3. Use the extension popup.
   - **Capture accounts on this page** reads only the rows already rendered.
   - **Capture and scroll** moves the Following list slowly, at most 40 viewport steps, and stops after 3 steps with no new usernames. Leave the popup open until it finishes.
4. The popup POSTs `{command:"import", accounts:[...]}` to `http://localhost:8000/api.php`. If that server is not running, the popup says so.
5. Review on the dashboard. Open a profile there only when you want to; the extension never clicks Follow or Unfollow.

The extension has no remote code and no analytics. Host access is `https://x.com/*`, `https://twitter.com/*`, and `http://localhost:8000/*`.

## Review

The dashboard shows progress, the first still-pending account, and counts by status and by type. Empty status counts as pending. `keep`, `unfollow`, and `moved` count as done.

- **keep**: leave them on the public account.
- **unfollow**: you decided to unfollow on the public account. The tool does not do it.
- **moved**: you will follow them from the private account instead. The tool does not do it.

Auto-open and sound are off until you turn them on. That choice, and the theme, stay in `localStorage` only.

## Hotkeys

- `1` keep
- `2` unfollow
- `3` moved
- `O` open `https://x.com/username` in a new tab
- `R` refetch
- `T` tech, `N` news, `P` porn, `G` girls, `F` fake, `E` personal, `U` other
- Enter in the note field saves the note. Esc leaves the field.

A type or a note does not advance the queue. After keep, unfollow, or moved, the next pending account loads. If auto-open is on, that profile opens after 400ms. Sound is a short beep.

## Files

- `dashboard.html` is the review UI.
- `api.php` reads and writes `following.json` and appends `actions.jsonl`.
- `extension/` is the unpacked capture extension.
- `following.example.json` shows the account shape. It is not loaded by the app.

## Data

`following.json`:

```json
{
  "accounts": [
    {
      "id": "stable x user id or username if id unknown",
      "username": "handle without @",
      "name": "display name",
      "bio": "",
      "url": "https://x.com/username",
      "status": "pending",
      "type": "",
      "note": ""
    }
  ]
}
```

Import matches on `id`, and falls back to username when the id was missing or was only the handle. A later capture can fill in a numeric id if the stored id was not numeric. An existing numeric id is not replaced. Status, type, and note on an existing row are left alone. Name, bio, url, and username update when the capture actually has them. New rows start as `pending`.

## Log

`actions.jsonl` is append-only. One JSON object per line:

```json
{"at":"2026-10-04T13:00:00-05:00","id":"","username":"","action":"import","type":"","status":"","note":"","added":1,"updated":0,"total":1}
```

`at` is local time in America/Chicago. Actions written by this tool: `keep`, `unfollow`, `moved`, `classify` (a type change), `note`, and `import`. Import is one summary line with `added`, `updated`, and `total`, not one line per account. `total` is the number of accounts in the file after that import. `reopen` is a reserved action name and is not emitted; there is no command that puts an account back in the queue.

## Capture limits

X virtualizes the Following list and changes its markup. The content script only sees DOM that is currently rendered. It looks for links whose path is `/username` inside Following rows, skips nav and sidebar links (home, explore, notifications, messages, settings, and similar) and the profile owner, and reads a display name and bio only when they are in that same row. A numeric id is stored only when a data attribute or link clearly has one; otherwise the id is the username. Scroll mode is capped and is not a full export of everyone you follow. Expect to capture, scroll the page yourself, and capture again.

## API

`GET /api.php` returns `total`, `pending`, `done`, `percentage`, `by_status`, `by_type`, and `current` (the first pending account, or null).

`POST /api.php` accepts:

- `{"command":"keep"|"unfollow"|"moved","id":"..."}`
- `{"command":"type","id":"...","type":"tech|news|porn|girls|fake|personal|other"}`
- `{"command":"note","id":"...","note":"..."}`
- `{"command":"import","accounts":[...]}`

Unknown commands get 400. Writes take an exclusive lock on `following.json.lock`, replace `following.json` by renaming a temp file, and append the log under that same lock. CORS reflects `Origin` for `http://localhost:8000` and `chrome-extension://...`, and allows `GET`, `POST`, and `Content-Type`.
