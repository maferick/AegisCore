{{--
    AegisCore admin backdrop — visual parity with the landing page.

    Registered via `PanelsRenderHook::HEAD_END` in AdminPanelProvider,
    so this <style> block lands at the end of <head> on every Filament
    admin page (including /admin/login). Keeping the CSS in a Blade
    view (instead of inline in the provider) keeps the PHP readable
    and lets designers iterate without touching a service provider.

    Layer stack mirrors resources/views/landing.blade.php:

      1. Hex-shield watermark (SVG data URI) — brand mark, centred.
      2. Sensor dot matrix                   — 28px grid of cyan dots.
      3. Cyan atmospheric glow (top-left).
      4. Gold atmospheric glow (bottom-right).
      5. Solid #0a0a0b base.

    Scoped to `html.dark` so light-mode users keep Filament's default
    palette (white cards on grey would clash with a dark HUD wash).
    `.fi-body` covers normal panel pages; `.fi-simple-body` covers the
    login / lock screens which use a different layout component.
--}}
<style>
    html.dark body.fi-body,
    html.dark body.fi-simple-body {
        background:
            url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64' fill='none'><path d='M32 4 L56 18 L56 46 L32 60 L8 46 L8 18 Z' stroke='%234fd0d0' stroke-width='0.6' stroke-opacity='0.28' fill='none'/><path d='M32 18 L44 25 L44 39 L32 46 L20 39 L20 25 Z' stroke='%23e5a900' stroke-width='0.4' stroke-opacity='0.22' fill='none'/><g stroke='%23e5a900' stroke-width='0.55' stroke-linecap='round' stroke-opacity='0.22'><line x1='32' y1='22' x2='32' y2='27'/><line x1='32' y1='37' x2='32' y2='42'/><line x1='22' y1='32' x2='27' y2='32'/><line x1='37' y1='32' x2='42' y2='32'/></g><circle cx='32' cy='32' r='1.5' fill='%234fd0d0' fill-opacity='0.25'/></svg>")
                center center / min(72vh, 72vw) no-repeat fixed,
            radial-gradient(rgba(79, 208, 208, 0.045) 1px, transparent 1.5px)
                0 0 / 28px 28px,
            radial-gradient(ellipse at 15% -10%, rgba(79, 208, 208, 0.10) 0%, transparent 45%),
            radial-gradient(ellipse at 85% 110%, rgba(229, 169, 0, 0.05) 0%, transparent 45%),
            #0a0a0b !important;
        background-attachment: fixed, fixed, fixed, fixed, scroll !important;
    }

    /* Panels (cards, widgets, tables) get a hairline cyan-tinted border
     * so they pick up the HUD language without drowning the content. */
    html.dark body.fi-body .fi-section,
    html.dark body.fi-body .fi-wi {
        border-color: rgba(79, 208, 208, 0.12);
    }

    /* Login screen card — same hairline accent so the first surface a
     * new operator sees already reads as "AegisCore", not "stock Filament". */
    html.dark body.fi-simple-body .fi-simple-main {
        border: 1px solid rgba(79, 208, 208, 0.18);
    }
</style>
