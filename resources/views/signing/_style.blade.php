<style>
    :root { --sign-navy: #1a365d; --sign-deep: #0f2440; --sign-ink: #374151; --sign-border: #e5e7eb; --sign-canvas: #f8fafc; --sign-gold: #fed136; }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--sign-canvas); color: var(--sign-ink); font: 1rem/1.5 Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
    header { background: var(--sign-deep); color: white; padding: 16px 24px; }
    main { max-width: 900px; margin: 32px auto; padding: 0 24px; }
    h1 { color: var(--sign-navy); font: 700 1.75rem/1.25 Montserrat, sans-serif; }
    .sign-card { background: white; border: 1px solid var(--sign-border); border-radius: 12px; overflow: hidden; margin-top: 24px; }
    .sign-bar { background: var(--sign-navy); color: white; padding: 16px; font: 600 .9rem/1.3 Montserrat, sans-serif; letter-spacing: .5px; }
    .sign-pad { padding: 24px; }
    p { max-width: 72ch; }
    label { display: block; color: var(--sign-navy); font-weight: 600; margin-bottom: 8px; }
    input:not([type=hidden]) { width: 100%; max-width: 460px; padding: 12px; font: inherit; border: 1px solid #d1d5db; border-radius: 8px; }
    button, .sign-link { display: inline-block; padding: 10px 16px; border: 1px solid var(--sign-navy); border-radius: 8px; font: 600 .9rem/1.5 Inter, sans-serif; color: var(--sign-navy); background: white; text-decoration: none; }
    button { background: var(--sign-gold); color: var(--sign-deep); border-color: var(--sign-gold); cursor: pointer; }
    button:hover, button:active { background: #fdc50c; }
    .sign-link:hover, .sign-link:active { background: var(--sign-navy); color: white; }
    button:focus-visible, a:focus-visible, input:focus-visible { outline: 3px solid #234179; outline-offset: 3px; }
    button:disabled { color: #6b7280; border-color: var(--sign-border); background: var(--sign-canvas); cursor: not-allowed; }
    object { border: 1px solid #d1d5db; }
    @media(max-width:700px) { main { margin: 16px auto; } .sign-pad { padding: 16px; } }
</style>
