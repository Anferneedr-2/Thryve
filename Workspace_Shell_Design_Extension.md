# Workspace shell: approved extension to the StartupLab brand design system

`StartupLab_Brand_Design_System.html` stays the source of truth for colour, type, buttons, forms and icons. It has no
navigation rail, side panels, bottom tab bar or full-width chat message style. These were added in P05A as an
owner-approved extension. Every value below comes from the brand tokens; nothing here introduces a new colour or font.

## Frame
- **Rail (84 px, 768 px and wider):** company mark, then Chat, Attendance, People, Agents, Settings (items depend on the
  role), then the bell ("Alerts") and the avatar menu (name and Account, theme, Sign out). No top bar.
- **Bottom tab bar (below 768 px):** Chat, Time, Alerts, More (More holds the remaining items and the account menu).
  Tabs are 52 px tall or more.
- **Page header (non-chat pages, 80 px):** title, optional tabs, optional actions; sticks to the top of the scrolling
  area. **Page body:** 32 to 40 px padding, 1,240 px content width, 20 px and 16 px side padding on phones. Tables scroll
  inside their own container; the page never scrolls sideways.
- **Tabs and URLs:** each tab has its own URL (`/attendance`, `/attendance/team`, `/people`, `/people/departments`,
  `/agents`, `/agents/usage`, `/agents/prices`, `/settings`, `/settings/health`, `/settings/updates`). Old URLs
  (`/team-attendance`, `/departments`, `/system`) redirect to the matching tab.

## Chat
- **Left panel (344 px):** New chat, Assistants (hidden for a person with one assistant), Recent chats with assistant
  filter chip, search, Archived view and a row menu. **Details panel (320 px):** tokens ring, files, about the assistant.
- **Breakpoints:** below 1024 px the left panel is an overlay; below 1280 px the details panel is an overlay; below
  768 px the chats panel is the home screen (full width) and a chat opens full screen with a Back button. Overlays close on
  Escape and on a tap outside, and focus returns to the button that opened them.
- **Messages:** AI replies are plain text on the page, no bubble or avatar, 17 px at 1.8 line height, 34 px between
  messages; only the person's message is a bubble, right aligned. Copy, rating and regenerate sit under each reply.
- **Rich content:** headings, nested lists, quotes, rules, links (http, https and mailto only), tables in their own scroll
  box, fenced code with a language label and a Copy button. Raw HTML is never rendered.
- **Composer:** one line that grows to 180 px, attach, microphone (voice typing), send or Stop.

## Touch and motion
- Controls are 44 px or larger on phones and touch screens.
- Panel animation is switched off when the person prefers reduced motion.
- Scrollbars are transparent with a muted thumb (brand blue on hover), set globally.

## Verification (ego lite)
Built-in matrix: sign-in, chat, attendance, people, agents and settings at 375, 768, 1024 and 1440 px in light and dark;
no sideways page scroll; rail or tab bar as expected; popups measured inside the screen. Developer fixtures:
`/design/answer` (every answer element and unsafe input) and `/design/components` (shell pieces).
