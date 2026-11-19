<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="60">
    <title>Updating</title>
    {{-- Standalone on purpose: during an update the built assets may be mid-replacement. Colours match the brand tokens. --}}
    <style>
        :root { --bg: #f8fafc; --surface: #ffffff; --line: #e2e8f0; --heading: #0f172a; --text: #37404c; --brand: #315eef; --brand-soft: #eef2ff; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0b1220; --surface: #111a2e; --line: #26334d; --heading: #f1f5f9; --text: #cbd5e1; --brand: #7f9cff; --brand-soft: #1b2652; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: var(--bg); color: var(--text); font: 400 14px/1.6 Inter, system-ui, -apple-system, sans-serif; }
        main { width: min(100%, 460px); background: var(--surface); border: 1px solid var(--line); border-radius: 20px; padding: 28px; box-shadow: 0 2px 8px rgba(15, 23, 42, .05); display: grid; gap: 12px; }
        .mark { width: 48px; height: 48px; border-radius: 14px; background: var(--brand-soft); color: var(--brand); display: grid; place-items: center; }
        h1 { margin: 0; color: var(--heading); font: 800 24px/1.2 "DM Sans", Inter, system-ui, sans-serif; letter-spacing: -.02em; }
        p { margin: 0; }
        small { color: #6f7a8a; }
    </style>
</head>
<body>
    <main role="status">
        <span class="mark" aria-hidden="true">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6v6l4 2"/><circle cx="12" cy="12" r="8.5"/></svg>
        </span>
        <h1>We are updating</h1>
        <p>{{ $message }}</p>
        <p><small>This page refreshes by itself. Your work is safe.</small></p>
    </main>
</body>
</html>
