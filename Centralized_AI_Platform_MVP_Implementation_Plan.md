# Centralized AI Platform — MVP Implementation Plan

**Project:** Thryve / StartupLab | **Target:** November 24, 2026 (Asia/Manila) | **Status:** Planning / not started

This is the living implementation tracker. The approved build specification and subsequent confirmed decisions govern scope; the latest StartupLab design system governs every frontend. No implementation task is considered complete without passing its acceptance criteria. The November 24 target is a target, not a waiver of release gates.

## 1. Scope baseline

| Area | Approved MVP behavior |
|---|---|
| Stack | Laravel API on cPanel, React + Tailwind, MariaDB, Hermes on company VPS; Claude and ChatGPT supported through authorized integrations |
| Authentication | Admin-created accounts only; username/password; employees change own password; admins reset employee passwords; deactivation preserves history; one active session; Super Admin can terminate sessions |
| Departments | Multiple per employee; categorization and access control, never token quota allocation |
| Attendance | Official record; login starts session; logout/browser closure ends session; multiple sessions accumulated within Philippine calendar day; idle default 30 minutes configurable by Super Admin; idle does not log out; exclude idle from work hours; employees view own; admins add/override with audit |
| Chat | New chat, history, search, rename, edit, regenerate, stop, stream, thumbs up/down, owner isolation; permanent retention; employee deletion archives rather than erases |
| Providers | Multiple authorized Claude/ChatGPT connections; Super Admin connect, reauthenticate, disable, remove, prioritize; next eligible connection on unavailability, return to preferred when available; hidden from employees; admins monitor status/quota; alerts at 80/90/100% |
| Usage | Daily individual token allowance only; no message or department limits; admin overrides/additional tokens; Asia/Manila midnight reset; employee sees usage and allowance; actual consumed tokens from failed/edited/regenerated attempts count; estimate distinctly when required |
| Files | Conversation attachments only; default 6 per message, 15 MB each, configurable by Super Admin; PDF, DOCX, XLSX, CSV, text, PNG/JPG analysis; DOCX, XLSX, CSV, PDF, PPTX, Markdown generation; permanent retention including archive; further formats require confirmation |
| Analytics | Configurable conversation idle analysis (default 15 min); refresh on resumed conversations; tasks/topics, five prompt subscores and overall/tip; employee sees own scores; admins manage topics; system allowance separate from employee allowance |
| Reporting | Admin/Super Admin view all employees and departments; custom plus 7/30/90-day filters; XLSX/CSV/PDF exports; access to conversation/files and archive logged automatically, no reason required; both roles view audit log |
| Security | First-login policy acknowledgment; sensitive-data warning by default, Super Admin can configure warning/block; no account lockout; Super Admin alone can edit/delete audit logs; preserve accountability separately subject to approval |
| Notifications | In-app bell/read/unread; employee token alerts; admin provider threshold/failure/reauth alerts; email via configurable SMTP; no attendance-correction notification |
| Settings | Super Admin only; company name/logo/favicon, light/dark, SMTP test, system health; general assistant system prompt not dashboard-editable |
| Wizard | Clean installation and first Super Admin setup; manual update approval; automatic backup/maintenance; update history/version; failure remains in maintenance with previous-version rollback |

**Deferred:** Coach AI, six department agents, HR/management reporting permissions, training rollout. Do not scaffold unnecessary agent-management screens, billing, self-registration, department quotas, message limits, or coding assistance.

## 2. Mandatory execution protocol

- Use one task ID per implementation prompt, in sequence. No broad refactors, speculative features, or parallel modifications to the same contract.
- Before each task: inspect existing code/schema, identify affected files and current behavior, state exact scope and constraints, then obtain approval for nontrivial production changes. Do not invent schema or endpoint behavior.
- Each implementation prompt uses: **Context → Objective → Scope → Constraints → Deliverable → Acceptance Criteria**. Link this plan and the relevant task ID rather than repeating the document.
- Work locally/staging first. Preserve prior passing behavior; use additive migrations where possible, and test migration and recovery on disposable data. Never migrate or reset production as part of a routine coding task.
- Use environment-based secrets, private storage, server-side authorization, input validation, and sanitized logs from the first affected task. Do not expose provider credentials, raw exception internals or cross-user data to clients.
- Update the task checkbox only after verification. Record changed files, commit/version, test evidence, failures and remaining blockers in the log. A checked phase requires every included task and gate to pass.
- Use the **EgoLite Browser skill (`ego-browser`)** for hands-on verification of every phase that introduces or modifies a frontend screen or browser workflow. Browser verification is required in addition to, not instead of, automated backend and integration tests. See the verification protocol below.
- A failing test blocks dependent tasks. Diagnose narrowly; revert the scoped change if recovery is more economical. Do not silently alter expected behavior to make a test pass.

## 3. Strict frontend design contract

**Authority:** `C:\Users\Rovick\Desktop\Thryve\StartupLab_Brand_Design_System.html` (updated version includes light/dark and chat components). Re-read the actual HTML before frontend work; do not use an earlier light-only revision. Preserve it as the reference; do not edit it to match implementation.

- Extract the actual CSS variables, styles and permitted icons into shared React/Tailwind tokens and reusable components. Validate any duplicate token definitions against the live reference; do not create a competing palette or theme.
- Core brand: blue `#315EEF` (hover `#2447CC`), navy `#0F172A`, slate text, white/light canvas. Use the actual dark overrides under `html[data-theme="dark"]` rather than guessing colors. Theme switching must apply to the whole interface.
- Typography: DM Sans display/headings (800), Inter body/navigation/actions, JetBrains Mono where appropriate; body at least 14px; use tabular numerals for dates/costs/stats.
- Match spacing, radius, shadows, motion and responsive rules in sections 03–13 and 26–29. Primary actions are pill-shaped, one primary per view; fields have 12px radius, 1.5px slate border, blue focus ring. Use semantic alert colors and reference icons; no arbitrary gradients or heavy shadows.
- For chat use sections 14–25: GPT-like sidebar and centered max-760px reading column, pinned pill composer, right-aligned blue user messages and open assistant layout, Markdown/rich content, action row, streaming/working states, file chips/progress, suggestions, history search, menus/modals and actionable error/limit states.
- Match chat interactions: Enter sends, Shift+Enter newline; Send becomes Stop while streaming; partial answer remains after cancellation; follow output only when near the bottom, otherwise show scroll affordance; preserve unsent text on failure; confirm destructive actions; touch actions remain usable.
- Apply the same tokens and component primitives to installation, login, employee settings, attendance, reporting tables, admin dashboards and all notifications. Reuse semantic component variants instead of custom per-page styling. Avoid copying demo data or enabling demo-only model/tool features.
- Verify each view at phone, tablet and desktop widths in both themes. Labels, focus states, keyboard navigation, accessible errors, reduced motion and contrast must work. Use the reference's 36px minimum tap targets (44px primary actions) and responsive behavior; handle wide tables without breaking layout.
- **Frontend gate:** compare actual page screenshots against relevant sections of the local reference; record light/dark and responsive evidence. No frontend task passes with a known design deviation. Ask before extending the design system for a component with no approved equivalent.

### Browser verification protocol — EgoLite

Before browser work, follow the installed EgoLite skill guide (`ego-browser skill`) and use its supported browser controls. Verify the running application, not merely its source or a static mockup.

1. Start the approved local or staging build; record the URL/build version and test account role. Use synthetic test data and authorized accounts only.
2. Open the affected screen in EgoLite; compare it against the matching section of `StartupLab_Brand_Design_System.html`. Check typography, spacing, brand tokens, components, light/dark themes and responsive layouts.
3. Exercise the task's actual acceptance criteria: normal flow, loading/empty/error states, form validation, keyboard interactions, cancellation, and permitted role behavior where applicable. Do not mark a control working solely because it is visible.
4. Capture relevant screenshots using non-sensitive test fixtures, record expected vs actual results, and log any failed case with its task ID. Do not capture real credentials or private employee records.
5. Fix only the failing scoped behavior and rerun the affected browser checks plus existing regression tests. A failed check blocks the associated task/phase until resolved or explicitly deferred by the owner.

| Phase | Required EgoLite verification |
|---|---|
| P01 | Shared component gallery, responsive layouts, both themes and accessibility states |
| P02 | Installation, login, policy acknowledgment, account settings and authorized account management |
| P03 | Attendance history, active/idle display and authorized admin corrections using test records |
| P04 | Provider management/monitoring UI, priority controls and quota/status/error displays using approved test connections or fixtures |
| P05 | Chat shell, history/search, send/stop, edit/regenerate, ratings, token warnings and failure recovery |
| P06 | Attachment chips, upload progress/errors and authorized generated-file downloads |
| P07 | Personal prompt scores, tips, loading/empty states and topic-management UI |
| P08 | Admin tabs, filters, archive, audit viewer and report exports |
| P09 | Branding/settings, notification bell, SMTP test and health dashboard |
| P10 | Update confirmation, maintenance page, version history and rollback controls on disposable staging only |
| P11 | Full end-to-end browser UAT and cross-theme/responsive regression review |

P00 remains a read-only discovery phase; no application browser pass is required until a runnable interface exists. Backend-only work is verified with relevant unit, integration and database tests. If EgoLite is unavailable, log the blocker and obtain approval for an alternative browser before marking frontend verification complete.

## 4. Dependency and decision gates

These are unresolved implementation questions, not permission to guess. Resolve only those required to unlock the next task.

| ID | Decision/evidence required | Blocks |
|---|---|---|
| D01 | Confirm repository location, existing portal source access, fresh DB vs migration, approved framework/runtime versions and cPanel deployment constraints (SSH, cron, HTTPS, PHP extensions, streaming, build artifacts). Inspect without edits. | P01–P02 |
| D02 | Confirm actual Hermes endpoint, authentication method, request/response/stream/cancel contract, general assistant availability and concurrent request isolation. | P04–P05 |
| D03 | Confirm supported provider authentication for the company, terms and quota telemetry. No reliance on consumer subscription sharing unless explicitly permitted. Define supported connect/reauth mechanism and when quota is unknown. | P04 |
| D04 | Confirm cPanel ↔ VPS authorized attachment transport, generated-file retrieval, file MIME/extension support and storage permissions. | P06 |
| D05 | Confirm chargeable token definition (input+output and any other provider categories), reservation/overage handling, estimate formula and whether cost currency is USD or another approved currency. | P04–P05 |
| D06 | Agree how closure/disconnect is detected, reconciliation interval and behavior for interrupted network, multi-tab, midnight crossing and login during an idle period. Browser-close signal is not guaranteed; validate fallback in staging. | P03 |
| D07 | Confirm employee/admin permission boundary for managing admins versus employees; who receives provider/system emails, email address requirements, employee warning threshold(s) and which events are emailed. | P02, P09 |
| D08 | Confirm edited-message and regenerated-answer history rules (branching vs replacement, admin visibility); behavior of archived chats on re-analysis and restore availability (not requested yet). | P05, P07 |
| D09 | Confirm analytics system token budget, exhausted-budget behavior, re-analysis timing/version policy and managed topic defaults. | P07 |
| D10 | Confirm backup destination, release packaging/version source, update artifact authenticity, recovery behavior and acceptable data loss window; do not promise rollback until restore is tested. | P10 |
| D11 | Confirm privacy policy content, sensitive-data categories warning/block behavior, permanent retention governance and whether a separate protected audit-log change history is approved. | P02, P05, P08, P11 |
| D12 | Confirm production go-live pilot vs everyone, employee count, capacity target and support/approval owner. | P11 |

## 5. Execution tracker

| Phase | Scope | Depends on | Status |
|---|---|---|---|
| P00 | Read-only verification and contracts | — | Partial: P00.01 local audit only; P00.02-P00.04 skipped by owner, open |
| P01 | Project foundation and frontend primitives | P00 | Tasks done locally; gate open (cPanel deploy not verified) |
| P02 | Installation, identity and permissions | P01 | Done locally (gate passed); cPanel install unverified |
| P03 | Official attendance | P02 | Built and tested locally; P03.01 and gate open (D06 rules need owner approval, independent time check pending) |
| P04 | Provider management and quota accounting | P02 | Not started |
| P05 | General chat and token enforcement | P04 | Not started |
| P06 | Attachments and generated files | P05 | Not started |
| P07 | Background analytics | P05, P06 | Not started |
| P08 | Admin reporting and archive | P03–P07 | Not started |
| P09 | Settings, notification and SMTP completion | P02, P04, P08 | Not started |
| P10 | Update, backup and rollback | P02, P09 | Not started |
| P11 | Integration QA, staging and launch | All previous | Not started |

The phase order is dependency-first. P03 and P04 may be assigned independently after P02 if their contracts and files do not overlap. Build P10 before production migration/launch; test update mechanics early in P02 without exposing incomplete controls.

### P00 — Verification and contracts

**Context:** Existing portal and Hermes capabilities must be verified before selecting implementation details. **Constraints:** Read-only; no production writes, secrets extraction, account changes or infrastructure modification.

- [ ] **P00.01 Repository and runtime audit** — Record workspace/repo state, existing source if available, PHP/extensions, MariaDB, Node/build, cPanel deployment and scheduler capabilities. **Done:** D01 resolved with evidence; actual paths and runtime constraints recorded.
- [ ] **P00.02 Hermes contract discovery** — Confirm the general agent and supported integration contract with authorized non-sensitive test inputs. **Done:** D02 resolved; output/stream/cancel/usage/errors and isolation capabilities documented or explicitly unsupported.
- [ ] **P00.03 Provider and file feasibility** — Map supported authentication, permitted usage, quota information, file transfer and output references. **Done:** D03/D04 recorded; unknowns become blockers, not fabricated capabilities.
- [ ] **P00.04 Architecture decisions** — Confirm unresolved rules D05–D12 with owner as needed; draft data model, API boundaries and screen map; obtain approval on release scope. **Done:** blocking decisions are either approved or explicitly tracked against affected phase; no code yet.

**Gate:** [ ] Approved architecture and task sequence; P01 can start without unknown foundational assumptions.

### P01 — Foundation and design primitives

**Objective:** Create the minimum Laravel/React/Tailwind application skeleton and shared UI foundation. **Scope:** No employee functionality yet. **Constraints:** Approved stack; use cPanel-compatible deployment; no microservices or additional frameworks without need.

- [x] **P01.01 Application scaffold** — Initialize agreed Laravel + React/Tailwind structure, configuration example, lockfiles, test setup, local MariaDB connectivity and reproducible build; preserve secrets outside source. **Done:** clean environment runs/builds/tests; documented start/build commands; no real credentials in repo.
- [x] **P01.02 Data and HTTP conventions** — Define migrations by feature, timestamps/UTC storage with Philippine business-day conversion, validation/error conventions, auth boundary, scheduler and private-storage abstraction. **Done:** isolated integration test confirms DB, API response shape and date conversion.
- [x] **P01.03 Design token implementation** — Port actual light/dark variables, typography, iconography and theme persistence to shared frontend assets. **Done:** token gallery matches HTML in both themes; no unrelated palette.
- [x] **P01.04 Shared components** — Buttons, inputs, layout/navigation, cards/tables, alerts/toasts, badges, dialogs, menus, loading/empty/error states. **Done:** component gallery works at phone/tablet/desktop, keyboard and both themes; no demo-only features.

**Gate:** [ ] Build/tests green; design gallery reviewed; skeleton deployable to nonproduction cPanel.

### P02 — Installer, authentication and permissions

**Objective:** Make the system installable and enforce identity/role boundaries before any employee data is stored. **Scope:** Identity, core settings and audit foundation. **Constraints:** No default admin password; no self-registration; no real environment changes during tests.

- [x] **P02.01 Core schema** — Migrations/models for users, roles, departments, user-department pivot, sessions, settings, policy acknowledgments and audit entries; price/topic schemas may be added in their own phases. **Done:** migrations/seeders repeat safely on disposable DB; multiple departments supported; referential constraints tested.
- [x] **P02.02 Install wizard** — Welcome → requirements → permissions → environment (DB/Hermes tests, first Super Admin) → migrations/seeders → finish; installation marker and route guards; handle safe retry. **Done:** clean cPanel-style install works, failures reveal actionable non-secret errors, installed state prevents reinstall; no manual file edits required.
- [x] **P02.03 Session lifecycle** — Username/password authentication, minimum approved password rules, secure password hashing, failed-login logging, no lockout, single active session, logout and Super Admin termination. **Done:** second device invalidates first; deactivated account denied; session cleanup tested; role guards enforced server-side.
- [x] **P02.04 Account management API** — Employee CRUD/deactivate, department membership, own password change and privileged reset, individual token limit field; enforce approved role boundary D07. **Done:** unauthorized role requests denied; deactivation retains related records; account changes audited.
- [x] **P02.05 Identity screens** — Design-compliant login, first-login notice and acknowledgment, account settings, admin people/department screens; no signup. **Done:** employee can sign in, acknowledge, change password; admin can manage authorized accounts; both themes validated.
- [x] **P02.06 Audit and settings services** — Central audit writer for login, failure, account change, report/file/conversation access, export, attendance override and system events; Super Admin-only settings writes. **Done:** attributable actor/action/target/time persisted; identities and secrets are not leaked; access tests pass.

**Gate:** [x] Clean install, account lifecycle, role matrix and session revocation tests pass. (Passed locally 2026-11-19; real cPanel install still unverified.)

### P03 — Official attendance

**Objective:** Deliver reliable Philippine-date attendance from authenticated activity. **Constraints:** Separate AI activity from attendance; no attendance-correction notifications; no fabricated browser-close certainty.

- [ ] **P03.01 Attendance data and event contract** — Attendance dates, session intervals, activity events, idle windows, configurable 30-minute threshold, correction metadata. **Done:** schema prevents duplicate daily rollups; D06 resolved.
- [x] **P03.02 Event capture and reconciliation** — Login/logout/activity heartbeat and agreed closure fallback; deduplicate multi-tab events; roll up across same-day sessions, idle and midnight boundaries. **Done:** deterministic tests cover login, idle, resume, close/reconnect, session replacement and Philippine midnight; active time excludes idle.
- [x] **P03.03 Attendance API and screens** — Employee own daily/history view; admin company records, missed-entry creation and overrides with audit. **Done:** cross-user employee access denied; correction history captures actor and change; no email/notification sent for correction.

**Gate:** [ ] Attendance time calculations independently checked; official record limitations documented and approved.

### P04 — Provider management and token accounting

**Objective:** Establish verified provider connectivity, status, usage and allocation before chat. **Constraints:** D02/D03/D05 required; only provider-supported authorization and quota mechanisms; if unavailable, mark unknown or blocked, never present guessed quota as exact. No credentials returned to browser.

- [ ] **P04.01 Hermes adapter** — Implement agreed authenticated request contract, timeout/error mapping and health check, with mocked integration tests. **Done:** authorized test call works, invalid credentials rejected and error states are categorized; no assumption of unverified features.
- [ ] **P04.02 Provider configuration and management** — Store connection metadata and secure credential references; Super Admin connect/reauth/disable/remove/priority through supported flow. **Done:** multiple permitted connections managed; admins can monitor only; removal does not erase usage history; secrets never shown in logs/UI.
- [ ] **P04.03 Eligible-provider routing** — Select highest-priority eligible authorized connection; approved failover on transient failure/unavailability; return to preferred when available; avoid duplicate charges on ambiguous outcomes. **Done:** controlled tests demonstrate normal routing, recovery, disabled connection exclusion and exhausted/unavailable provider handling without bypassing provider restrictions.
- [ ] **P04.04 Usage ledger** — Record request/attempt ID, user/conversation, provider/model, input/output tokens, real/estimated flag, cost, latency, status, price snapshot. **Done:** each chargeable attempt recorded once; failures recorded accurately; price changes do not silently rewrite historical costs; no fake counts.
- [ ] **P04.05 Individual daily token allowance** — Per-account allowance, usage and grant ledger; Asia/Manila reset, actual consumption, concurrency-safe accounting, enforce D05 rules; system analysis allowance separate. **Done:** token exhaustion blocks requests; midnight and concurrent requests tested; no message/department quota logic.
- [ ] **P04.06 Provider monitoring screens** — Admin/Super Admin provider status, token usage/remaining percentage where supported, last checked and real/unknown indicator; editable price list per approved admin permissions. **Done:** 80/90/100% threshold events created once per applicable quota window, provider errors/reauth events emitted; design gate passed.

**Gate:** [ ] Verified permitted provider access, accurate ledger and quota behavior; no claim of unsupported subscription integration.

### P05 — General AI chat

**Objective:** Connect the existing Hermes General AI Assistant to authenticated employees. **Constraints:** No department agents or Coach; no coding help; provider switching hidden from employees; private conversations; hard token checks.

- [ ] **P05.01 Conversation and message persistence** — Conversation/message tables with ownership, timestamps, rating, soft-deletion/archive and agreed edited/regenerated history; request IDs. **Done:** owner CRUD, search/rename/archive and history behavior covered; other employees cannot view data.
- [ ] **P05.02 Chat orchestration** — Validate session/ownership/allowance, sensitive-data policy warning/block, save message, forward authorized context, stream and persist response, account for attempts. **Done:** multi-turn flow, blocked/quota cases, cancellation, provider failure and missing telemetry tested; coding/out-of-scope behavior validated against General AI Assistant requirements.
- [ ] **P05.03 Chat shell and composer** — Implement design sections 14–19 and 21–22; welcome, suggested office tasks, sidebar/history/search, pinned composer, streaming states and stop. **Done:** Enter/Shift+Enter, stop retains partial output, scroll logic, responsive sidebar, both themes and failed-send draft preservation work.
- [ ] **P05.04 Actions and rich content** — Safe rendered Markdown, tables/quotes/citations when applicable; permitted copy, edit, regenerate, rating, rename/delete confirmation and archive behavior. **Done:** D08 rules verified; actions persist, new attempts count; unsafe content not executed in browser; design sections 15–17 and 23–25 matched.
- [ ] **P05.05 Employee usage display** — Show consumed/remaining personal daily token allowance, exhaustion and warning states; no provider/account identifiers. **Done:** values match ledger; warning threshold conforms to D07; post-request usage updates correctly.

**Gate:** [ ] Full chat flow passes with real authorized agent plus controlled failure tests; ownership and accounting verified.

### P06 — Attachments and generated files

**Objective:** Enable office file analysis and downloads within chat. **Constraints:** D04 required; no standalone file library; retain conversation files permanently, including archive; no public or unscoped file paths.

- [ ] **P06.01 Attachment schema and upload** — Store ownership/conversation/message metadata, file kind/size, storage key and generated flag; configurable 6 files / 15 MB; validate allowed types and actual file content. **Done:** size/count/type boundaries tested; invalid and unauthorized access rejected; private storage confirmed.
- [ ] **P06.02 Hermes file analysis transport** — Use verified transfer contract to send allowed files and images; isolate request-specific storage and handle failure/cleanup without risking archived records. **Done:** PDF, DOCX, XLSX, CSV, text, PNG and JPG test fixtures analyzed correctly; other users' files inaccessible.
- [ ] **P06.03 Generated files** — Retrieve and validate allowed outputs (DOCX/XLSX/CSV/PDF/PPTX/MD); store authorized download reference and metadata. **Done:** files open in expected applications; downloads enforce ownership; failed generation does not create fake link.
- [ ] **P06.04 File UI** — Implement reference section 20 upload chips, progress, remove, error, previews/downloads and composer integration. **Done:** limit config reflected, errors actionable, upload failure preserves draft; both themes validated.

**Gate:** [ ] End-to-end analysis and generation pass; permanent archive storage and authorized downloads verified.

### P07 — Background analytics and prompt scoring

**Objective:** Convert completed conversations into stored reports without reprocessing on every dashboard visit. **Constraints:** No new employee data claims beyond AI-assisted tasks; no background usage charged to employees.

- [ ] **P07.01 Analytics schema and topic management** — Topics, conversation analysis, task label, five 1–5 subscores (clarity/context/specifics/refinement/verification), average, tip, analyzed message version. **Done:** required fields validated; admin can manage topic list, employee cannot.
- [ ] **P07.02 Scheduled analysis** — Use Laravel scheduler and idempotent job, configurable 15-minute idle threshold, separate approved system token allowance; reanalyze after resumed conversation changes. **Done:** one result per analyzed version; retry/failure behavior and exhausted system quota validated; no duplicate employee charges.
- [ ] **P07.03 Employee scores** — Own prompt score, trends and improvement tips from stored analysis. **Done:** employee only sees own results, missing/in-progress states clear, no live AI call on report load.

**Gate:** [ ] Sample conversations produce accurate stored summaries/scores; resumption refresh and accounting pass.

### P08 — Admin reporting, archive and audit

**Objective:** Build administrative views strictly from verified modules and stored records. **Constraints:** No HR/management portal; no reason field for inspection; all report/file/conversation views and exports logged.

- [ ] **P08.01 Dashboard shell and queries** — Overview, attendance, usage/cost, tasks/topics, prompt quality, people, conversations and audit tabs; shared 7/30/90/custom dates, employee/department filters. **Done:** admin-only routes and aggregates tested against fixtures; multi-department filters avoid double counting company totals.
- [ ] **P08.02 Usage and attendance reports** — Active/idle/time in/out; usage, token costs, provider quality, latency and failures, adoption. **Done:** reports reconcile to source rows and date boundaries; unknown/estimated counts labeled.
- [ ] **P08.03 Employee analytics reports** — AI-assisted daily tasks, most-asked topics, prompt-quality trend, skills/gaps and monthly employee summary. **Done:** stored analytics source, no claim of full work output, tested filter results.
- [ ] **P08.04 Conversation and attachment viewer** — Authorized admin inspection plus archived deleted-conversation list/files. **Done:** employee deletion hides conversation from own active list while admin archive retains it; every inspection logged; no reason prompt; secure downloads.
- [ ] **P08.05 Audit viewer and Super Admin changes** — Both admin roles can view/filter; only Super Admin modifies/deletes under approved policy D11. **Done:** permission tests pass; approved accountability mechanism captures changes if accepted; destructive actions confirm.
- [ ] **P08.06 Export** — XLSX/CSV/PDF from permitted report filters with correct dates/values and audit event. **Done:** exports match dashboard and open correctly; no cross-role disclosure; spreadsheet values are safe as data.

**Gate:** [ ] All report tabs and exports verified; ordinary employee access denied; archive and audit behavior tested.

### P09 — System settings and notifications

**Objective:** Complete Super Admin configuration and event delivery. **Constraints:** Settings exclusive to Super Admin; no general-agent system-prompt editor; no attendance override alerts.

- [ ] **P09.01 Settings UI and validation** — Company name/logo/favicon; idle timeout, analysis delay, file limits, sensitive warning/block and applicable system allowance; light/dark theme control. **Done:** settings validated and persist; branding doesn't violate design system; unauthorized writes/reads denied.
- [ ] **P09.02 Notification event service** — Persist per-recipient notifications/read state from token thresholds, provider status/reauth and system/update events; dedup and delivery rules. **Done:** expected recipients receive events once, read/unread persists, employee cannot inspect other recipients.
- [ ] **P09.03 Notification bell and screens** — In-app bell with unread count/list and read controls; operator status messages. **Done:** matches reference menus/toasts/alerts, keyboard and mobile work.
- [ ] **P09.04 SMTP setup and email** — Super Admin-only credentials (encrypted), connection/test email, templates and asynchronous delivery according to D07. **Done:** authorized test mailbox receives mail; invalid SMTP fails safely; secret never exposed; in-app notifications remain functional when email fails.
- [ ] **P09.05 Health dashboard** — Database, Hermes, providers and SMTP checks with status/time; no credentials or raw diagnostic secrets. **Done:** dependency failures visible to Super Admin; admin provider alerts still visible in permitted monitoring.

**Gate:** [ ] Configuration, alerts and both notification channels verified without real employee spam.

### P10 — Updates, backups and rollback

**Objective:** Make maintenance and future upgrades safe on target cPanel hosting. **Constraints:** Super Admin approval required; no arbitrary remote artifact execution; no update without successful backup; destructive restore only explicitly authorized.

- [ ] **P10.01 Version and migration detection** — Track release version and actual pending migration names against DB, version/update history; update available popup/notification. **Done:** no pending state when unchanged; only Super Admin can initiate approved update.
- [ ] **P10.02 Backup and maintenance workflow** — Automatic verified DB backup and required app/file snapshots before mutations; maintenance mode; protect update routes and serialize update execution. **Done:** backup failure aborts before migration; other users see maintenance notice only during update; D10 satisfied.
- [ ] **P10.03 Update execution and recovery** — Apply approved package/migrations/idempotent seeds, log results, show version history; failure remains in maintenance; provide Super Admin-approved previous version rollback. **Done:** successful disposable upgrade and induced nonproduction failure restore compatible app/DB/files; interrupted recovery documented; no promise of zero data loss.
- [ ] **P10.04 Regular backup operations** — Schedule daily DB/files/Hermes folders backup to approved off-server location, restricted access and documented restore. **Done:** backup job monitored; recovery drill and evidence recorded.

**Gate:** [ ] Installation + update + failure + rollback tested end to end on nonproduction environment with approved data protection.

### P11 — Integrated QA, staging and go-live

**Objective:** Release only the confirmed MVP that passes cross-module checks. **Constraints:** No unapproved production destructive action or unverified provider account arrangement; protect employee data.

- [ ] **P11.01 Regression suite** — Run build/lint/type checks as configured, migration and backend feature tests; end-to-end user/admin/Super Admin flows; record results and fix one issue at a time.
- [ ] **P11.02 Authorization and data isolation** — Review generated source for missing server-side policies, secrets exposure, upload safety, concurrent requests, session revocation and private file access; validate intended denial cases using authorized test accounts.
- [ ] **P11.03 Attendance and accounting validation** — Compare time calculations, corrections and midnight transitions; check ledger against available provider usage, separate system budget, alerts, failure and recovery.
- [ ] **P11.04 UX and accessibility review** — Every screen across mobile/tablet/desktop and light/dark, compare with latest StartupLab HTML; chat interaction and errors included; record screenshots and deviations, fix before acceptance.
- [ ] **P11.05 Staging deployment** — Configure cPanel public root, HTTPS, MariaDB, secrets, scheduler, Hermes, file transport and logs; execute clean install and update/rollback rehearsal; measure approved concurrent capacity.
- [ ] **P11.06 Business UAT** — Approved test users execute employee chat/files/attendance, admin monitoring/reports and Super Admin settings/setup; policy acknowledgment confirmed, pilot/all decision D12 recorded.
- [ ] **P11.07 Production release approval** — Confirm verified backup, rollback plan, provider permission/capacity, health, monitoring, privacy notice, access matrix and sign-off; deploy only on approval; record release/version and post-release smoke test.

**Gate:** [ ] All critical checks passed, no unresolved release blocker, owner signed off. If target date is at risk, report blockers and request a scope/date decision; do not silently omit confirmed functionality.

## 6. Proposed data ownership and contracts

Validate exact table names/fields with approved schema in P00/P02. Add migrations in their owning phase, not one massive speculative migration. These are logical entities, not claims about the existing DB.

| Owner phase | Entities | Invariant |
|---|---|---|
| P02 | Users, roles, departments, user-department membership, sessions, policy acknowledgment, settings, audit | One identity and active session; roles enforced server-side |
| P03 | Attendance, login/work sessions, activity events, corrections | One daily rollup per user/Philippine date; no duplicate elapsed intervals |
| P04 | Provider connections, price list, usage records, token grants/allocation, system quota | Account-level allowance; immutable historical cost basis; one record per chargeable attempt |
| P05 | Conversations, messages, feedback, archive metadata | Owner isolation; employee archive never permanently deletes retained records |
| P06 | Attachments/generated artifacts | Owned, typed, private, retrievable only via authorized API |
| P07 | Topics, conversation analyses | Analysis linked to conversation/version; scores within rubric |
| P09 | Notifications, delivery records, SMTP settings | Recipient isolation; no duplicate alert per defined event/window |
| P10 | Releases, update history, backup metadata | Verified backup before update; auditable version state |

The browser talks only to Laravel. Laravel owns identity, authorization, accounting, persistence and business rules. Hermes owns AI processing and authorized file analysis/generation. External provider credentials and account routing are server-side; telemetry is shown only to authorized operators. Define actual interface shapes after discovery rather than hardcoding guesses.

## 7. Screen inventory and ownership

| Screen/group | Phase | Access |
|---|---|---|
| Install wizard / update wizard / maintenance page | P02/P10 | Installer before installation; Super Admin updates; maintenance notice for everyone else |
| Login / first-login policy / personal settings | P02 | Authenticated user except login |
| Attendance history | P03 | Own employee record |
| Provider management / usage monitoring | P04 | Super Admin management; Admin read-only monitoring |
| Chat, welcome, conversation search/history, token allowance | P05 | All authenticated roles, private own chats |
| Conversation uploads and generated downloads | P06 | Conversation owner and authorized admins |
| Personal prompt scores and tips | P07 | Own employee scores |
| Admin overview, attendance, usage, analytics, people, conversations/archive, audit | P08 | Admin/Super Admin |
| Notification bell / list | P09 | Own notifications |
| Branding, thresholds, SMTP, health, system settings | P09 | Super Admin |
| Version/update history | P10 | Super Admin |

## 8. Target-day sequencing

| Philippine dates | Planned work |
|---|---|
| Nov 19 | P00 and P01; verify hosting/Hermes/provider/file blockers |
| Nov 20 | P02 and P03; install/identity/attendance |
| Nov 21 | P04–P06; provider/token/chat/files |
| Nov 22 | P07–P08; analytics/reporting |
| Nov 23 | P09–P10 and P11 staging/QA |
| Nov 24 | P11 approval, release and smoke tests |

This is an aggressive proposed allocation consistent with the existing target, not a confirmed capacity estimate. Requirements expanded during clarification (provider management, token limits, SMTP, dark theme and safe rollback). Re-estimate after P00; keep release gates intact. Stabilization after launch is for defects and approved follow-on work, not undisclosed incomplete MVP controls.

## 9. Task handoff and progress log

Copy this entry after each task; do not duplicate the full implementation plan in coding prompts.

```text
Task ID:
Status: Not started / In progress / Blocked / In review / Done
Owner:
Context:
Objective:
Scope and affected files:
Constraints and preserved behavior:
Deliverable:
Acceptance criteria:
Verification commands / evidence:
EgoLite browser verification (URL, role, viewport, theme, cases, screenshots/results):
Design comparison (if frontend):
Commit/version:
Known issues / decisions required:
Next eligible task:
```

| Date | Task | Status | Evidence / outcome |
|---|---|---|---|
| 2026-11-19 | Plan creation | Done | Approved business decisions consolidated; updated HTML reference includes light/dark and chat components. Implementation not started. |
| 2026-11-19 | Verification protocol | Done | EgoLite browser QA added to all applicable frontend phase gates; implementation and browser testing not started. |
| 2026-11-19 | P00.01 Repository and runtime audit | Partial (local verified; cPanel assumed) | Local: no app source, not a git repo; PHP 8.3.29, Composer 2.8.11, Node 24.12.0/npm 11.6.2, MariaDB 10.4.32 (XAMPP), Git/SSH present, rsync absent. **Assumed defaults, NOT verified on the host:** cPanel PHP 8.2+ and MariaDB 10.4+; cron available; HTTPS available; SSE streaming unverified (fallback: polling); Node build runs locally and built assets are uploaded; fresh DB, no migration; repo to be created at the Thryve folder. Verify all in P11.05 staging. D01 stays open for these items; P01 may start under these assumptions. |
| 2026-11-19 | P01.01 Application scaffold | Done (local) | Created `platform/` (Laravel 13.43, React 19, Tailwind 4, Vite; MariaDB 10.4 local DBs `thryve_dev`/`thryve_test`). Files: composer/package manifests and lockfiles, `vite.config.js`, `resources/js/app.jsx`, `resources/views/app.blade.php`, `routes/web.php` (SPA catch-all excluding `/api`), `phpunit.xml`, `tests/Feature/FoundationTest.php`, `README.md`, `.env.example`. Evidence: `npm run build` OK; `php artisan test` 4/4 pass; migrations run on MariaDB; no secrets staged (`.env` ignored). `git init` done, staged, **not committed**. **Finding:** Laravel 13 requires PHP ^8.3, so the earlier assumed cPanel PHP 8.2+ must be PHP 8.3+; verify on host (D01). Default Laravel font removed; brand tokens come in P01.03. Not yet verified: clean-machine install, cPanel deploy. |
| 2026-11-19 | P01.02 Data and HTTP conventions | Done (local) | Added `config/thryve.php` (business tz Asia/Manila, private disk), `app/Support/BusinessDay.php` (UTC storage; Manila business-date and half-open UTC day bounds), `app/Http/Responses/Api.php` + exception rendering in `bootstrap/app.php` (envelopes `{data,meta?}` / `{error:{code,message,details?}}`, 401/403/404/422/500 with no internals leaked), `routes/api.php` (public `/api/health`, `auth` group placeholder until P02.03), `thryve:heartbeat` scheduled every minute (cPanel cron proof), non-served `private` disk. Evidence: `php artisan test` 14/14 pass (DB UTC round-trip, Manila date/midnight boundaries, each envelope, scheduler, private disk). Not verified: real cron on cPanel. Migrations convention: one additive migration set per owning phase (no migration added here). |
| 2026-11-19 | P01.03 Design token implementation | Done (local) | `platform/resources/css/tokens.css` extracted verbatim from the reference (`:root` and `html[data-theme="dark"]`); `app.css` maps Tailwind utilities onto those variables (no second palette) and mirrors reference base styles; 41-symbol icon sprite copied to `resources/icons/sprite.svg` and inlined by `app.blade.php`; `lib/theme.js` + head script use the reference `sl-theme` key; fonts via the same Google Fonts link; temporary `/` token gallery (`pages/TokenGallery.jsx`, **remove before launch**). Evidence (EgoLite script, URL http://127.0.0.1:8000/, no login, synthetic): 45 tokens compared light and dark, all equal in value (only serialization differs, e.g. `#fff` vs `#ffffff`, `rgba(...,.12)` vs `#315eef1f`); same 41 icon ids, 0 broken `<use>`; h1 DM Sans 800, body Inter 14px, mono loaded; toggle persists across reload. Screenshots via built-in browser pane because EgoLite `captureScreenshot` timed out repeatedly (page itself healthy): dark and light desktop and 375px phone, no horizontal overflow. **Gaps:** tablet width not checked; full component comparison against sections 03-13 belongs to P01.04; alpha values in minified CSS round slightly (.12 → .1216). |
| 2026-11-19 | P01.04 Shared components | In review (checkbox left open) | `platform/resources/css/components.css` extracted verbatim from the reference (buttons, pills, nav, cards, fields, alerts, toast, menu, modal, controls, tabs, banner, empty, spinner, skeleton, table); React primitives in `resources/js/components/ui/` (Button/IconButton, Form fields with label/help/error wiring, Alert/Banner/Pill/Spinner/Skeleton/EmptyState/ErrorState/Toast, native-`<dialog>` Dialog/ConfirmDialog, keyboard Menu, Card/Stat/Table/Tabs/AppNav/Page); gallery at `/` (tokens at `/tokens`), synthetic data, **remove before launch**. Base styles moved to `@layer base` so utilities work. Evidence (built-in browser pane, http://127.0.0.1:8000/, no login): 14/14 PHP tests pass; build OK; no console errors; computed styles match reference values (button 44px/36px sm, pill radius, field radius 12px, card radius 20px/24px padding, alert 14px); light and dark screenshots; phone 375 (no overflow, 24px gutter, nav collapses to menu), tablet 768 (2 columns), desktop; table scrolls inside its wrapper; scripted keyboard checks: tab arrows, menu arrows/Esc/focus return, dialog labelled and focus inside, toast live region, field error with aria-invalid/describedby. **Open items needing owner decision:** (1) **APPROVED 2026-11-19 by owner:** built-in browser pane may be used for screenshots when EgoLite's screenshot call times out (EgoLite DOM/script checks still preferred). (2) Reference icon buttons are 34px, below the plan's 36px tap-target minimum; extending (e.g. 44px on touch) needs approval. (3) Reference danger button is white on light red (#f87171) in dark mode, ~2.8:1 contrast; unchanged pending approval. (4) Esc-closes-dialog was simulated, not a real keypress; Esc-to-close on native dialog is browser behavior. (5) Input 1.5px border renders ~0.8px at this pane's 1.25 pixel ratio (browser snapping, not CSS). |
| 2026-11-19 | P01.04 follow-up (owner approved "fix both") | Done (local) | Appended two owner-approved deviations to `components.css`: icon buttons 36x36 (was 34) and dark-mode danger button uses `#c62828` (white text 5.62:1; was ~2.8:1). Verified in the built-in browser pane: all visible icon buttons measure 36x36, danger contrast 5.62 in light and dark, no overflow, no console errors; PHP tests still pass. P01.04 checkbox now checked. Items 4-5 above remain informational. **P01 gate still open:** nonproduction cPanel deployment of the skeleton is not yet verified (also D01 cPanel assumptions, tablet/phone covered only for gallery). |
| 2026-11-19 | P02.01 Core schema | Done (local) | Migrations `2026_11_19_000001-6` (roles, users, departments + department_user, settings, policy_acknowledgments, audit_entries); scaffold `0001_01_01_000000` reduced to the `sessions` table and `password_reset_tokens` dropped (admins reset passwords). Models Role/User/Department/Setting/PolicyAcknowledgment/AuditEntry, `UserFactory` (role/inactive states), idempotent `RoleSeeder` (employee, admin, super_admin), `DatabaseSeeder` creates no accounts. Design choices: users have username (unique), nullable unique email (D07 pending), `is_active`/`deactivated_at`/`last_login_at`; `role_id` and `is_active` not mass-assignable; every FK to users/roles/departments is `restrictOnDelete` so history survives (accounts are deactivated, never deleted); audit entries snapshot `actor_username`. **Not added (not specified, no invented schema):** token limit field (P02.04/P04), policy text (D11), force-password-change flag. Evidence: `php artisan test` 28/28 pass on MariaDB `thryve_test` (tables, repeatable seeder, migrate:fresh twice then migrate, multiple departments both ways, duplicate-membership/username/email rejected, nullable email, delete of role/user/department with history rejected, deactivation keeps records, ack uniqueness, settings JSON, audit fields, mass-assignment guard + password hashing); local `thryve_dev` rebuilt with `migrate:fresh --seed` (3 roles, 0 users). Uncommitted. |
| 2026-11-19 | P02.02 Install wizard | Done (local; cPanel not yet verified) | Backend: `app/Install/{Installer,EnvFile,InstallException}.php`, `InstallController`, middleware `installed` / `not-installed`, routes `/api/install/{checks,test-database,test-hermes,run}` (throttled, 404 once installed; other API routes return 503 `not_installed` until then), marker file `storage/app/installed.json`, uninstalled app uses file session/cache (no DB needed). Steps: database test, write `.env` (APP_ENV=production, APP_DEBUG=false, DB_*, HERMES_*, generated APP_KEY), migrate, seed roles, create Super Admin (kept on retry), audit `system.installed`, marker last. Frontend: `pages/InstallWizard.jsx` (Welcome, Server check, Database, Hermes, Super Admin, Install) using the shared components; `lib/api.js`. Evidence: `php artisan test` 44/44 pass (checks, guards, actionable non-secret DB errors for bad password/unknown DB/unreachable host, validation, failed-then-retry, full install on MariaDB with env/APP_KEY/hash/audit checks, rerun keeps Super Admin and does not rotate the key, `.env` quoting round-trips through phpdotenv, BOM stripped). EgoLite run on a disposable instance (temp env/marker via `THRYVE_ENV_PATH`/`THRYVE_INSTALL_MARKER`, disposable DB `thryve_wizard_demo`, synthetic admin): full flow through all 6 screens, Continue disabled until a good DB test, bad password / missing DB / bad Hermes URL / empty, short and mismatched password errors shown, no password echoed, install created 14 tables and a `super_admin` with bcrypt hash, wizard replaced by the app after reload, installer API 404 afterwards. Built-in pane (approved) for light/dark/phone screenshots; no horizontal overflow at 375px. Bugs found and fixed during verification: APP_KEY not written when another source supplied a key; BOM in `.env`/`.env.example` (from an earlier PowerShell edit, now stripped); footer buttons animated between steps (keyed per step). Disposable DB, temp env and server removed. **Open / risks:** (1) Until installation finishes, the installer endpoints are open to anyone who can reach the URL (first-come takeover, and the Hermes/DB test endpoints make server-side connections); mitigations in place: only before install, public-IP/https rule for Hermes outside local, short timeouts, throttle; a one-time install code is a possible hardening (needs owner decision, adds a manual step). (2) Hermes test is reachability only; contract is D02. (3) Min password length 12 is a placeholder pending policy (D07/D11). (4) Not verified: real cPanel install (PHP 8.3, writable `.env`, MariaDB privileges), tablet width, failure UI for a mid-install failure (covered by backend tests only). (5) Wizard has no "sign in" yet: "Continue to Thryve" opens the temporary gallery until P02.05. Uncommitted. |
| 2026-11-19 | P02.03 Session lifecycle | Done (local) | Migration `...000007` adds `users.session_id` (the one valid session). `AuthController` (login/me/logout), `SessionAdminController` (Super Admin terminate), middleware `current-session` (rejects replaced/terminated/deactivated sessions with distinct codes) and `role:` (server-side guard; 403 `forbidden`), `UserResource` (no password/session id), `app/Support/Audit.php` (minimal central writer, drops secret-looking keys; P02.06 extends it), `thryve:prune-sessions` hourly. Routes use the `web` group (cookie session + CSRF). Behaviour: same generic failure message for unknown user / wrong password / deactivated; failures audited with reason and never the password; no account lockout (only a 30/min per-IP request throttle on login, which is not a lockout; confirm acceptable); session id regenerated on login; session cookie HttpOnly, SameSite=lax, browser-session lifetime (`expire_on_close` default true, lifetime still 120 min, both placeholders for D06). Design choice: stale sessions are rejected on next contact (with a clear "signed in elsewhere" message) and then removed, instead of being deleted at login. Evidence: `php artisan test` 64/64 pass (login/me/logout, fixation, generic errors, audit, no lockout over 12 failures, deactivation kills a live session, second device invalidates first and vice versa, other users unaffected, role matrix employee/admin 403 / super_admin 200 / unauth 401 / unknown 404, terminate then re-login, database session rows, prune command, cookie flags, audit metadata scrubbing). Test-harness lessons recorded: JSON test requests need `withCredentials()` to send cookies and the session store must be reset per simulated device. No UI yet (P02.05). Uncommitted. |
| 2026-11-19 | P02.04 Account management API | Done (local) | Migration `...000008` adds nullable `users.daily_token_limit` (account level; meaning of NULL/overrides is P04/D05). `UserController` (list with search/role/active/department filters + pagination, create, show, update, deactivate, reactivate, privileged password reset), `DepartmentController` (CRUD, delete blocked while members exist), `AccountController` (own password change: current password required, must differ, confirmation), `UserPolicy`, routes under `role:admin,super_admin`. **Assumed role boundary for D07 (needs owner confirmation):** Super Admin manages everyone; Admin manages Employee accounts only (cannot create/edit/deactivate/reset Admins or Super Admins, cannot assign roles above Employee); employees manage nothing. Username is immutable; no user deletion (deactivate only; deactivation also ends the session); cannot change own role or deactivate self; last-active-Super-Admin guard. Passwords never returned or logged; every change audited with actor/target/fields only. Also added the first-login policy gate for P02.05: `GET /api/policy`, `POST /api/policy/acknowledge`, middleware `policy` closing management routes until the current policy version is acknowledged (version bump forces re-acknowledgment); policy text in `config/thryve.php` is a **placeholder pending D11**. Evidence: `php artisan test` 88/88 pass (access matrix for unauth/employee, admin vs admin/super boundary, validation incl. duplicates, multi-department assignment and filtering, search/pagination, deactivate keeps history and kills sessions, reactivate, reset and own-change password flows, department rules, audit completeness, policy gate and versioning). Not included (not specified): force-change-on-first-login flag. Uncommitted. |
| 2026-11-19 | P02.05 Identity screens | Done (local) | Frontend: `lib/{api,auth,router,util}`, pages Login, PolicyAck, Home, Account (profile + change password), People (list, search, role/status/department filters, pagination, add/edit/reset/deactivate/reactivate/end-session with confirmations, generated temporary passwords), Departments (CRUD), `AppLayout` (role-aware nav, account menu with sign out); no sign-up anywhere. SPA uses cookie session + CSRF header; session loss (replaced, ended, deactivated) returns to Login with an explanation, both live and after reload. Developer galleries moved to `/design/components` and `/design/tokens` (**remove before launch**). Evidence (EgoLite on a disposable instance, port 8001, thryve_dev with synthetic users; built-in pane for screenshots): wrong password and unknown user show the identical message and clear the password; new employee is forced through the policy screen (Continue disabled until ticked, API 403 until acknowledged) and never sees it again; employee gets "no access" on /people and API 403; account password change shows field errors for wrong current/too short/mismatch, success toast, old password rejected, new works; admin sees People + Departments, 22 people over 2 pages (15+7), search/role/department filters correct, duplicate username error, create with generated password and token limit, edit (negative limit rejected), reset password, deactivate (confirm, cancel path) and reactivate; admin has no menu on Admin/Super rows and a single-role select; Super Admin sees End session, 3 role options, no deactivate/end-session on self; two devices: second sign-in as same account shows "signed in somewhere else" on the first, and an admin-ended session shows "ended by an administrator" on the next action; audit trail matches actions. Bugs found and fixed: guest `/api/*` requests without an Accept header crashed with a 500 (now 401 envelope, regression test added); dialogs could not scroll on short screens; dialogs were stuck top-left because Tailwind's reset removes the centering margin (this also affected the P01.04 gallery, never visually checked then); returning visitors got no explanation after a replaced session. Tests: 89/89. Layout numerically verified at 375px (24px gutters, no horizontal overflow, table scrolls inside its wrapper); pane screenshots at 375px looked clipped, which was the screenshot frame, not the app. **Not verified:** tablet width for these screens, side-by-side comparison with reference sections 03-13 beyond reused components, keyboard-only walk-through of the new screens, real browser-close behaviour. Another `php artisan serve` (not started by me) is still running on port 8000; left alone. Uncommitted. |
| 2026-11-19 | P02.06 Audit and settings services | Done (local) | `app/Support/Audit.php` is the central writer (actor id + username snapshot, action, target, IP, time; secret-looking metadata keys dropped). `AuditEntry` is now append-only in code: update/delete throw unless inside `AuditEntry::withMutation()` (reserved for the Super Admin audit tools, P08.05/D11; permission switches off again even after an exception). `app/Support/Settings.php` + `SettingsController` (`GET/PATCH /api/settings`, Super Admin only at the route and again in the service): registry in `config/thryve.php` (company name, attendance idle minutes 30, analysis delay 15, files per message 6, max MB 15, sensitive-data warn/block) with defaults and validation, all-or-nothing writes, unknown keys rejected, before/after values audited, `secret` settings never returned (only `is_set`) and audited as a list of names without values. Evidence: `php artisan test` 103/103 (access matrix incl. unauthorized writes leave no rows, defaults, typed persistence, validation without partial saves, audit contents, no-op patches not audited, secret handling, audit scrubbing, append-only behaviour, no HTTP route can edit audit entries). Bug found by the tests: my own audit scrubber removed a field named `secrets_changed`; renamed. Settings UI is P09; SMTP/system token budget keys are added with their phases. Uncommitted. |
| 2026-11-19 | P03 Official attendance (P03.01 left open, P03.02-03 done) | Built, local | Schema `...000009`: `work_sessions`, `attendance_intervals` (non-overlapping active/idle ledger already split at Manila midnight), `attendance_days` (UNIQUE user+date; computed values plus admin overrides), `attendance_corrections` (history). NOT NULL times use `dateTime` because MariaDB 10.4 rejects several NOT NULL `timestamp` columns (found by tests; relevant to older cPanel MariaDB). `app/Attendance/Tracker.php`: sign-in starts a session and replaces any open one; heartbeats split elapsed time into ACTIVE (within the idle threshold of last interaction, `idle_minutes` setting, default 30) and IDLE (never counted as work, never logs anyone out); out-of-order/duplicate/multi-tab heartbeats are harmless; silence over 150 s ends the session at its last heartbeat (no invented time); logout/close counts time up to now; terminate/deactivate cut at last heartbeat; `thryve:attendance-reconcile` sweeps silent sessions every minute. API: `POST /api/attendance/heartbeat|close`, `GET /api/attendance/me` (own data only), admin `GET /api/attendance` (date/person/department filters, pagination), `POST /api/attendance/corrections` (create missed day or override; follows the account boundary, no self-correction, no future dates, times must fall on the chosen Manila day, audited, **no notification**), `GET /api/attendance/days/{id}/corrections`. UI: heartbeat client with interaction tracking and keepalive close on `pagehide`, pages My attendance and Team attendance (filters, correction dialog, history), all shown in Philippine time. **D06 defaults I chose (NOT approved, please confirm):** heartbeat every 30 s, silence timeout 150 s, closure = best-effort `pagehide` message plus sweep, idle default 30 min (configurable via Settings), midnight = Asia/Manila. Evidence: `php artisan test` 143/143 (22 tracker scenarios incl. login, idle, resume, close, silent reconnect, sweep, replacement, midnight splits, UTC-vs-Manila date, multi-tab, login during idle, no gaps/overlaps invariant, configurable threshold; 21 API tests incl. own-data-only, admin filters, corrections/boundaries/audit/no notifications). Browser (EgoLite, real clock, idle threshold set to 1 min on a disposable instance): active time accrued while interacting; after ~100 s without input the page showed idle time and an "Idle now" pill while staying signed in; admin Team page filters, correct, history, add missed day (typed date/time fields work; Manila times displayed correctly, e.g. 9:00 AM-6:00 PM); `pagehide` close message observed to end the session in 4 of 5 navigations (one did not arrive: expected, that is what the sweep is for). Bugs fixed: admin nav (6 links) overflowed between 640 and ~760 px, nav now collapses below 768 px (deviation from the reference's 640 px rule, owner to confirm); several test scenarios had assumed silent gaps count as idle (they correctly do not). **Open:** (1) gate requires an independent check of the time calculations and approval of the documented limitations: browser-close is not guaranteed, background tabs are throttled, a laptop asleep ends the session at its last heartbeat, time between the last heartbeat and a hard crash is lost (up to ~150 s), clock skew is avoided because only server time is used, someone leaving the page open and interacting counts as active. (2) Not tested: real cPanel cron timing of the sweep, tablet/phone layouts of the two new pages beyond the nav fix, keyboard-only use. Uncommitted. |
| 2026-11-19 | P09 and P10 (backend built and tested; screens and browser checks NOT finished; no checkboxes ticked) | In progress | **P09:** settings registry extended (email/SMTP, default theme) with secrets encrypted at rest and never returned; `Notifier` (audiences, once-per-window dedupe, per-recipient rows, queued email with delivery records and safe failure categories, no attendance-correction notices); bell endpoints scoped to the signed-in user, unread count carried on the heartbeat; SMTP test endpoint; logo/favicon upload (png/jpg/webp/ico only, content-checked, served from private storage with nosniff and a locked-down CSP); health checks (database, Hermes reachability, email, scheduler, queue, storage, backups) with no credentials in output. Screens written but only built, never opened in a browser: Settings, notification bell, System > Health, branding in the shell. **P10:** `VERSION` file; signed-package updates (RSA-SHA256 over the package hash; refused when no public key is installed), zip-slip/symlink/allow-list/protected-path checks, staged review, typed-version approval, maintenance mode (file flag plus bypass cookie, scheduler pauses), PDO-based database dump and file snapshot with checksum verification, one-step-per-call state machine (backup, verify, files, migrate, seed, finalize), cancel-before-change if the backup fails, stay-in-maintenance and approved rollback if a later step fails, history kept in files so it survives a database restore, `thryve:backup` daily at 02:00 Manila with retention and optional off-server disk, `thryve:restore`, `thryve:release keygen/package/sign` for the owner. Evidence: `php artisan test` 252/252. **Not done:** the System > Updates screen is a placeholder (backend and API are ready); no browser verification of any P09/P10 screen; no tablet/phone/dark checks; no real cPanel run; update and rollback never rehearsed on a real MariaDB with the full schema (tests use a scratch table plus a read-only full dump). **Needs your decisions:** D10 (backup destination, release source, acceptable data loss; the off-server copy is unset so health shows a warning); D07 (who gets which alerts and emails; I assumed admins for provider alerts, Super Admins for system events); the new bell icon (not in the reference icon set, added in the same style); nav collapse raised to 1024 px (reference says 640); Hermes-folder backups must be set up on the VPS, Laravel cannot do them. Windows note: PHP here needs an openssl.cnf to create keys; the OpenSsl helper class locates one. Uncommitted. |

**Next immediate task:** P00.01 — read-only repository and runtime audit. Do not scaffold or build until its prerequisite decisions are recorded.
