# AegisCore EVE Log Uploader — Member Guide

This small program ships your EVE Online client logs to your
alliance's AegisCore intelligence server. It runs in the background,
uses negligible CPU/RAM, and never modifies or deletes your logs.

**You stay in control.** You can stop, disable, or remove it at any
time. You can see every byte it has uploaded before you turn it on.

---

## What it does

- Watches a few specific EVE log folders (Gamelogs, Chatlogs).
- Uploads new lines as they appear. Encrypted in transit (HTTPS).
- Tracks where it stopped so a reboot resumes cleanly.
- Reports its own status to a page on the AegisCore portal so you
  can verify it's working.

## What it does NOT do

- No screenshots.
- No scanning of files outside the EVE log folders.
- No copying or moving your files.
- No deleting anything, ever.
- No automated punitive action — the analytics on the server side
  are advisory only.

---

## Install

You'll need:

1. **The .exe.** Your alliance leadership distributed
   `AegisCore.EveLogUploader.exe` (and this guide). Save it
   somewhere persistent, e.g.:

   ```
   C:\Program Files\AegisCore\AegisCore.EveLogUploader.exe
   ```

2. **A token from the portal.** Open the AegisCore portal, sign in
   with your character, and go to **Intelligence → EVE Log
   Uploaders**. Click **Issue token**, give the install a name
   ("Home PC", "Laptop"), and copy the JSON block it shows you. The
   raw token is shown **once** — copy it immediately.

3. **Save the JSON block as your config.** Put it at:

   ```
   %APPDATA%\AegisCore\EveLogUploader\config.json
   ```

   (Paste `%APPDATA%` into Windows Explorer's address bar to
   navigate there.)

   Example shape:

   ```json
   {
     "api_base_url": "https://winterco.killsineve.online",
     "api_token": "1234abcd…64hexchars…",
     "client_id": "abcd1234-….-….-….",
     "auto_discover_eve_logs": true,
     "upload_interval_seconds": 10,
     "max_chunk_bytes": 262144
   }
   ```

4. **Run.** Either:

   - Double-click the .exe to run it as a console app (good for
     testing).
   - Or install it as a Windows service so it starts on boot:

     ```
     Right-click "install-service.cmd" → "Run as administrator"
     ```

   To uninstall:

     ```
     Right-click "uninstall-service.cmd" → "Run as administrator"
     ```

---

## How to verify it's working

1. Wait 1–2 minutes after starting it.
2. Open the AegisCore portal → **Intelligence → EVE Log Uploaders**.
3. Find your install in the list. The **last seen** column should
   say "a few seconds ago" or "1 minute ago".
4. **Files** and **bytes received** should be growing as you play.

If `last seen` stays empty for more than a couple of minutes:

- Check that `api_token` and `client_id` were copied correctly.
- Make sure your machine has internet and can reach
  `https://winterco.killsineve.online`.
- If you installed as a service: check the Services control panel
  for "AegisCore EVE Log Uploader" — it should be **Running**.

---

## What it watches by default

The auto-discover finds whichever of these folders exist on your
machine:

- `Documents\EVE\logs\Gamelogs`
- `Documents\EVE\logs\Chatlogs`
- `OneDrive\Documents\EVE\logs\Gamelogs`
- `OneDrive\Documents\EVE\logs\Chatlogs`
- `OneDrive\Downloads\Documenten\EVE\logs\Gamelogs` (Dutch
  localization)
- `OneDrive\Downloads\Documenten\EVE\logs\Chatlogs`
- `Downloads\Documenten\EVE\logs\Gamelogs`
- `Downloads\Documenten\EVE\logs\Chatlogs`

Only these. The uploader never scans elsewhere.

If your EVE logs live somewhere weird, add the folder to
`watch_paths` in `config.json`:

```json
"watch_paths": [
  "D:\\Games\\EVE\\logs\\Gamelogs"
],
```

---

## How to disable temporarily

- **Console mode:** close the window. It stops immediately.
- **Service mode:**

  ```
  Services control panel → "AegisCore EVE Log Uploader" → Stop
  ```

  When you want it back, click **Start**. Or set the **Startup
  type** to **Manual** so it doesn't start on boot.

---

## How to remove completely

1. Stop the service (above).
2. Run `uninstall-service.cmd` as administrator.
3. Delete:
   - `C:\Program Files\AegisCore\` (the .exe folder)
   - `%APPDATA%\AegisCore\EveLogUploader\` (config + state)
4. Open the portal → **EVE Log Uploaders** → click **revoke** on
   your install. This invalidates the token server-side.

You're done. No data persists on your machine after step 3, and the
server-side token can't be reused.

---

## What happens to logs already uploaded

Already-uploaded events stay on the AegisCore server. If you want
old uploads gone too, message your alliance leadership — they can
delete the file rows from `eve_log_files` and the cascade will
remove the events.

---

## Privacy

- Your raw chat / fleet / intel logs are visible to **you** (your
  account) and to **alliance leadership / admins** who have access
  to the audited cross-user view.
- Every cross-user view writes an entry to `eve_log_access_audit`
  with the viewer's user id, the rows exposed, and a timestamp.
  Leadership can review this audit log.
- The Counter-Intel analytics that consume your logs publish
  **summarised counts and timestamps** on the dossier card — never
  raw chat content.

---

## Troubleshooting

**"Created stub config" warning in console output**
First-run created an empty config. Fill in `api_token` and
`client_id` from the portal page. The uploader will pick up the
edit within 60 seconds — no restart needed.

**"api_token missing" repeated warnings**
You're past first run but `api_token` is still empty in
`config.json`. Same fix.

**Uploads succeed but `bytes received` doesn't grow**
The uploader has caught up to your current EVE log state and is
waiting for new lines. Play a bit; it'll resume.

**Server says `offset_mismatch`**
The uploader auto-resyncs to the server's offset on the next tick.
You'll see a one-line `Resyncing` warning in the console; nothing
to do.

**Lots of "retry" messages**
Network blip or server reload. The uploader backs off automatically
(up to 60 seconds). If it persists for hours, ping leadership.

---

## Questions

Ask in your alliance Counter-Intel channel. Include:

- The install's `client_id` (from the portal page or `config.json`).
- The last few lines of console output if you have it open.
- Whatever the **EVE Log Uploaders** page shows for your install
  (last seen, bytes, errors).

That's enough for leadership to debug.
