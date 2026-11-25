# Thryve: completed tasks (summary)

Report date: 2026-11-25. Covers items 2 to 5 from `Thryve_Notes.md`. Everything is built and tested on the local app. Nothing is committed, and nothing has been seen on the live site.

## Status at a glance

| Item | What it delivers | Expected output met? |
|---|---|---|
| 2. Tips on /scores | Employees see their best prompting tips first, ahead of the numbers | Yes, with one agreed change |
| 3. Log in as | A Super Admin can open an employee's account without signing the employee out | Yes |
| 4. Invitation email | A new person with an email address is emailed their login details and must choose their own password at first sign-in | Works; wording is a placeholder and live setup is pending |
| 5. Chatbot access on create | Chatbots are chosen in the Add person form | Yes, except the "always on" default line (waits for item 1) |
| 1. Default chatbot | Not started | n/a |

## What each item delivers

**Item 2: tips first.** The /scores page opens with a "Tips to try first" panel: one "Do this first" tip, then up to three more, each linking to the conversation it came from. Tips come from the employee's weakest area. Admins still see summaries only.
*Agreed change:* the notes asked for the most repeated tip. Tips are free text and cannot be counted that way, so the top tip comes from the lowest-scoring conversation in the weakest area.

**Item 3: log in as.** On the People page, a Super Admin can open an employee's account in their own browser, with a banner and a one-click way back. The employee's session, attendance and password are untouched. Every start and end is audited under the Super Admin's name. It is blocked for accounts that are not active employees, that have not set their own password, or that have not accepted the usage policy.

**Item 4: invitation email.**
- A new account with an email address is queued an invitation with the company name, username, starting password and a sign-in button, in the brand style of the design template.
- If there is no address, email is off, or sending fails, the account is still created and the admin is told.
- The password is never written to the audit log, and the queued job is encrypted.
- New accounts, and accounts reset by an admin, must choose their own password at first sign-in before anything else opens.
- SMTP (Gmail) is set up and working on the local app.

> **PLACEHOLDER: the invitation message wording is not final.** The layout and details work; the final text must be changed in both email views (`resources/views/mail/invitation-html.blade.php` and `invitation.blade.php`).

**Item 5: chatbot access on create.** The Add person form lists the available chatbots as tick boxes for Employees (hidden for Admin and Super Admin). The account and its access are saved together or not at all, and the change is audited.

## Checks

- Whole test suite: 622 tests, 609 pass. The 13 errors are all "GD extension is not installed" on this machine and are unrelated.
- Each item was also checked in the browser on desktop and phone width. The invitation email was delivered to a local test mailbox and to Gmail; it has not been checked in Outlook or Apple Mail.

## Still open

- **Item 1** (default chatbot), including the Hermes key, the new agent's details, assigning the old agent to affected employees, and the switch plan.
- **Item 4:** the final email wording, and on the live site: `APP_URL`, SMTP and the cPanel cron job that runs the queue.
- **Not verified:** tips with real scored data (no Report agent exists yet), and a few smaller points listed in `Thryve_Notes.md`.
- **Housekeeping:** commit the work (keep `vendor/` and `package-lock.json` changes out), and note that four deactivated accounts with history remain.
