# Updates, backups and recovery runbook

Audience: the Super Admin and whoever has shell access to the server. Everything here was rehearsed on a disposable copy
of the platform with its own database (see the P10 log entry in `Centralized_AI_Platform_MVP_Implementation_Plan.md`).
It has **not** yet been run on a real cPanel host. Nothing here promises zero data loss.

## Decisions (D10, made by the assistant on the owner's instruction "you decide", 2026-11-20; change any of them)

| Question | Decision | Why |
|---|---|---|
| Where do off-server backups go? | **SFTP to the Hermes VPS**, folder `/var/backups/thryve`, a dedicated user with a key (no password) | A different machine from the cPanel host, already owned, no new vendor or cost. Needs `league/flysystem-sftp-v3` (installed). Switch to S3 later by changing one disk in `config/filesystems.php`. |
| How long are backups kept? | Nightly backups **14 days**, here and off-server; the backup taken before an update **30 days** (never the one a failed or running update still needs) | Two weeks covers a problem noticed after a weekend; a month covers a bad update found late. Both are settings in `config/thryve.php`. |
| How much data may be lost? | **Up to 24 hours** after a disaster (nightly backup), plus a fresh backup before every update. Recovery target: **1 hour** with the steps below | Matches the nightly schedule; staff can re-enter a day of chat history if needed. Make it tighter later by scheduling the backup more often. |
| How do releases reach the server? | By your **GitHub Action deploy** (files only), after which a **Super Admin runs the database wizard** in Settings; or, without Git, by uploading a signed package. The server never downloads or runs anything on its own | Matches how your other systems work and still meets "no arbitrary remote artifact execution": a push only changes files, and nothing touches the database until a Super Admin approves. |
| What is promised about rollback? | A rollback restores the files and database to the moment the update's backup was taken, and anything saved since is lost. Verified in rehearsal, not yet on a real cPanel host | No promise of zero data loss. |
| Are the Hermes folders on the VPS backed up? | **No** (owner decision, 2026-11-20: not needed) | The VPS and its Hermes setup are treated as rebuildable; the platform's own data (database, uploaded files) is what is protected. |

## What is protected, and what is not

| Thing | How | Where it lives |
|---|---|---|
| Database (every table) | Full dump at 02:00 Philippine time (`php artisan thryve:backup`), plus one before every update | `storage/app/private/backups/<id>/db.sql` |
| Uploaded files and branding | Same backup (`storage/app/private/files`, `storage/app/private/branding`) | `files.zip` in the same folder |
| The application code | A snapshot of the files an update will replace, taken just before it | `files.zip` of the update's backup |
| Off-server copy | The SFTP disk `offsite` (set `THRYVE_BACKUP_OFFSITE_DISK=offsite` and the `THRYVE_OFFSITE_*` values in `.env`) | `/var/backups/thryve/thryve-backups/<id>/` on the VPS |
| Hermes folders on the VPS | **Not backed up, by owner decision.** If the VPS is lost, Hermes and the agents must be set up again and the platform's agent keys re-created | n/a |
| `.env` and the signing keys | **Not in any backup, on purpose.** Keep your own copy of `.env` somewhere safe; the private signing key never goes on the server | Owner |

**Data-loss window.** A restore returns the system to the moment of that backup. Anything saved after it is lost: up to
24 hours for the nightly backup, or the length of the update for a rollback.

## Deploying with Git (GitHub Action to cPanel, no terminal) - the normal way

This is the flow the other systems use, modelled on the update wizard in Ribo_New.

1. You push to `main`. Your GitHub Action copies the new code to cPanel. The action must **not** upload or delete `.env`
   or `storage/`, and cPanel has no Node or Composer shell, so the action itself must run `composer install --no-dev` and
   `npm run build` and upload `vendor/` and `public/build/`.
2. The platform notices by itself: it compares the migration files that arrived with the migrations the database has run.
3. If there are new ones, **everyone except Super Admins** sees the "We are updating" notice (open pages show it
   automatically and return by themselves). Nothing breaks for them even though the code is ahead of the database.
4. A Super Admin signs in and is taken straight to **Settings > Updates and backups**, which shows "The database needs an
   update" with the list of changes. They press **Update the database**.
5. The wizard puts the site in maintenance, takes a backup, checks it, applies the migrations, runs the idempotent seeders,
   clears caches and lifts maintenance. Press **Continue**. If a step fails, the site stays in maintenance and **Restore the
   database from the backup** (type `rollback`) puts the data back; the new code stays on the server, so the wizard asks again
   until you deploy the previous code or fix the migration and deploy again.
6. If the backup cannot be made, nothing is changed and the wizard says so.

Notes: the version number is whatever the `VERSION` file in git says; the wizard does not change it. Rolling back the
database does not roll back the code; to go back fully, push the previous commit as well. The wizard needs no signing key.

## The alternative: a signed package upload (no Git, or no CI)

### Making a release (owner's computer, never the server)

```
php artisan thryve:release keygen <folder outside the project>      once; keep the private key secret
copy thryve-release-public.pem to the server as storage/app/update-public.pem
php artisan thryve:release package release-1.2.3.zip --release=1.2.3 --notes="What changed"
php artisan thryve:release sign release-1.2.3.zip <path>/thryve-release-private.pem
```

Only the zip and its `.sig` are uploaded, in **Settings > Updates and backups**. The server accepts a package only if the
signature matches the public key it holds, and only if it is newer, needs a PHP version the server has, and contains
nothing outside the allowed folders (never `.env`, `storage/`, `bootstrap/cache`).

### Installing an update

1. Upload the zip and the `.sig`, press **Check package**. Nothing on the live site changes. Super Admins get an
   "Update ... is ready to review" notification.
2. Review the version, file count, notes and database changes. Press **Approve and start update** and type the version.
3. Keep the page open. It runs one step per request: backup, check the backup, replace files, migrate, seed, finish.
   Everyone else sees "We are updating" (open pages show the same notice by themselves and come back by themselves).
4. If the **backup** or its check fails, nothing was changed: the update goes back to "staged", maintenance is lifted.
5. If a **later step fails**, the site stays in maintenance and **Roll back** is offered (type `rollback`). It puts the
   old files back, then restores the database from the backup taken in step 3, and removes any table the failed update
   created. Work saved by people since the update started is lost, which is why maintenance blocks everyone else.
6. A finished update can also be rolled back from the same page (same data-loss rule: anything saved after the update
   finished is lost).

#### If the server or your browser dies in the middle

The state is kept in files (`storage/app/updates/state.json`), not the database, and the maintenance flag is a file
(`storage/framework/thryve-maintenance.json`), so both survive a crash and a database restore.

- The site stays in maintenance. Open **Settings > Updates and backups** as the same Super Admin in the same browser:
  the page sees the update is still running and carries on from the step it stopped at. (Rehearsed: stopped after
  "backup" and "check", reopened later, finished.)
- If you are on another browser or lost the cookie, you are held out by the maintenance page too. Use the command line:
  `php artisan thryve:backups` lists backups; `php artisan thryve:restore <id> --force` puts the files and database back;
  then delete `storage/framework/thryve-maintenance.json` and run `php artisan optimize:clear`.

## Restoring from a backup (disaster recovery)

From the command line (over SSH, or the cPanel terminal):

```
php artisan thryve:backups                    # list backup ids
php artisan thryve:restore <id> --force       # replaces the database and the backed-up files
php artisan optimize:clear
```

If the server's own backup folder is gone, copy the folder `thryve-backups/<id>/` from the off-server disk back to
`storage/app/private/backups/<id>/` first (it holds `db.sql`, `files.zip`, `meta.json`; the checksums in `meta.json` are
checked before anything is replaced). Rehearsal result: 28 tables wiped and one dropped, local backups deleted, restored
from the off-server copy alone in 3 seconds; a checksum of every table matched the original.

After a restore, sign in, open **Settings > Health** and confirm every line is green; open a chat and attendance page.

## Checks the system does for you

- **Settings > Health > Backups** warns when there is no backup, the last one is older than a day, or the last off-server
  copy failed.
- A backup is verified (checksum, complete, table count) before it is trusted; an update backup is also compared with the
  live row counts, and the update refuses to continue if they differ. While an update runs, attendance heartbeats from
  the updating admin's browser are paused so that comparison stays honest (time in maintenance is not counted for them).
- After each scheduled run, nightly backups older than 14 days are removed here and on the off-server disk, and update backups
  older than 30 days are removed here (never the one an unfinished or failed update still needs).

## Moving a chatbot's chats to another device

Used to bring the conversations of one chatbot (for example the General AI Assistant) to another installation of the platform,
without replacing anything that is already there. It does not use the full backup, so nothing on the receiving side is overwritten.

**On the device that has the chats**

```
php artisan thryve:export-chats --users=superadmin,Robi
```

This writes `storage/app/transfer/general-chats.json`: the conversations, messages, ratings, replaced-answer history and attached
files of the chosen people with the General AI Assistant (`--agent=...` picks another chatbot). Deleted chats are left out. It never
contains passwords, access keys or usage records. The file is private conversation text: that folder is ignored by git, so it
cannot be committed by accident, and it should only be copied to your own device (USB drive, private cloud folder).

**On the other device**

1. Have the same code (a `git pull` of the version that includes the transfer migration) and make sure the people in the file exist
   there with the same usernames. Nobody is created by the import.
2. Copy `general-chats.json` into `storage/app/transfer/` (create the folder if needed).
3. Run `php artisan migrate`. The migration `2026_11_20_120000_import_transferred_general_chats` finds the file and imports it. On a
   server with no terminal it runs in the database wizard, so upload the file to `storage/app/transfer/` first (cPanel File Manager).
4. People who did not exist yet are listed in the log (`storage/logs/laravel.log`) and skipped. Create them, then run
   `php artisan thryve:import-chats` (also the way to import when the file arrived after the migration ran). `--dry-run` shows what
   would happen. Importing is safe to repeat: a conversation that is already there is recognised and left alone.

**The chatbot itself, and its access key.** If the other device has no chatbot with the same short name, importing the chats creates one with
the same name, address and profile but **without an access key**: keys are secrets, so they are never inside the chat file, and never in
git. The chats are readable, but the chatbot cannot answer until it has a key. There are two ways to give it one:

- *Bring the same key* (so both devices use the same Hermes profile, nothing to change on the server). On the device that has the key,
  run `php artisan thryve:export-agent-key` and type a passphrase of your own twice (at least 10 characters, shown nowhere). It writes
  `storage/app/transfer/general-ai-assistant.key.json`, an AES-256-GCM encrypted file that is useless without the passphrase; the
  command prints only the last 8 characters (fingerprint) of the key. Copy that file to the other device's `storage/app/transfer/`,
  import the chats first (that creates the chatbot), then run `php artisan thryve:import-agent-key`, type the same passphrase, and compare the fingerprint
  with the one printed by the export. Open Agents and press **Test connection**. Send the passphrase by a different route than the file.
  A different key already on the chatbot is only replaced with `--replace`.
- *Make a new key* for that device in Agents. A Hermes profile accepts one key at a time, so this means a separate Hermes profile for the
  second device, or reinstalling the new key on the server (which stops the first device's key until it is updated too).

Whichever way: **rotating the key on one device breaks the other** until the new key is brought across again.

## Known limits

- **Windows development machines:** a running `php artisan serve` keeps `artisan` locked, so an update that replaces the
  `artisan` file fails at "replace files" (and rolls back cleanly). Linux and cPanel hosts do not have this problem. The
  rehearsals used a package without that one file.
- The update replaces code files and runs migrations; it does not run `composer install`. Include `vendor/` in the
  package (`--with-vendor`) when dependencies change.
- The SFTP disk is configured but has **not been connected to a real VPS yet** (rehearsals used a local folder as the
  off-server disk). Until `THRYVE_BACKUP_OFFSITE_DISK=offsite` is set and one backup has reached the VPS, Health shows a
  warning. Use the host fingerprint setting so the server is not impersonated.
- `vendor/` is not part of an update unless the package is built with `--with-vendor`; the new SFTP package needs that
  for the first deployment.
