{{--
    AegisCore admin backdrop — visual parity with the landing page.

    Registered via `PanelsRenderHook::HEAD_END` in AdminPanelProvider,
    so this <style> block lands at the end of <head> on every Filament
    admin page (including /admin/login). Keeping the CSS in a Blade
    view (instead of inline in the provider) keeps the PHP readable
    and lets designers iterate without touching a service provider.

    Layer stack mirrors resources/views/landing.blade.php:

      1. Constellation / intel-network map  — dots + thin traces (star
         systems linked by jump routes + active intel), a few gold
         "flagged target" systems ringed with faint reticles.
      2. Sensor dot matrix (28px grid)      — cyan dots on a fixed
         lattice; HUD texture under the star map.
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
            url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1600 900' fill='none' preserveAspectRatio='xMidYMid slice'><g stroke='%234fd0d0' stroke-width='0.7' stroke-opacity='0.18' stroke-linecap='round'><line x1='140' y1='180' x2='300' y2='120'/><line x1='140' y1='180' x2='220' y2='300'/><line x1='300' y1='120' x2='460' y2='210'/><line x1='300' y1='120' x2='220' y2='300'/><line x1='460' y1='210' x2='380' y2='340'/><line x1='460' y1='210' x2='560' y2='300'/><line x1='220' y1='300' x2='380' y2='340'/><line x1='380' y1='340' x2='560' y2='300'/><line x1='560' y1='300' x2='720' y2='200'/><line x1='560' y1='300' x2='640' y2='440'/><line x1='720' y1='200' x2='880' y2='280'/><line x1='880' y1='280' x2='1040' y2='180'/><line x1='880' y1='280' x2='820' y2='480'/><line x1='1040' y1='180' x2='1200' y2='260'/><line x1='1040' y1='180' x2='1000' y2='420'/><line x1='1200' y1='260' x2='1360' y2='200'/><line x1='1200' y1='260' x2='1000' y2='420'/><line x1='1360' y1='200' x2='1480' y2='340'/><line x1='1480' y1='340' x2='1320' y2='460'/><line x1='640' y1='440' x2='820' y2='480'/><line x1='820' y1='480' x2='1000' y2='420'/><line x1='1000' y1='420' x2='1160' y2='540'/><line x1='1160' y1='540' x2='1320' y2='460'/><line x1='1160' y1='540' x2='1440' y2='620'/><line x1='1320' y1='460' x2='1440' y2='620'/><line x1='220' y1='300' x2='220' y2='480'/><line x1='640' y1='440' x2='580' y2='620'/><line x1='580' y1='620' x2='760' y2='660'/><line x1='760' y1='660' x2='940' y2='720'/><line x1='940' y1='720' x2='1100' y2='780'/><line x1='1100' y1='780' x2='1280' y2='720'/><line x1='1280' y1='720' x2='1440' y2='620'/><line x1='400' y1='540' x2='580' y2='620'/><line x1='400' y1='540' x2='220' y2='480'/><line x1='220' y1='480' x2='300' y2='680'/><line x1='300' y1='680' x2='480' y2='760'/><line x1='480' y1='760' x2='660' y2='820'/><line x1='660' y1='820' x2='940' y2='720'/></g><g stroke='%234fd0d0' stroke-width='0.5' stroke-opacity='0.12' stroke-dasharray='3 5'><line x1='140' y1='180' x2='560' y2='300'/><line x1='720' y1='200' x2='1040' y2='180'/><line x1='220' y1='480' x2='940' y2='720'/><line x1='1000' y1='420' x2='1280' y2='720'/></g><g fill='%234fd0d0' fill-opacity='0.4'><circle cx='140' cy='180' r='1.5'/><circle cx='300' cy='120' r='1.5'/><circle cx='460' cy='210' r='1.5'/><circle cx='220' cy='300' r='1.5'/><circle cx='380' cy='340' r='1.5'/><circle cx='720' cy='200' r='1.5'/><circle cx='880' cy='280' r='1.5'/><circle cx='1040' cy='180' r='1.5'/><circle cx='1200' cy='260' r='1.5'/><circle cx='1360' cy='200' r='1.5'/><circle cx='1480' cy='340' r='1.5'/><circle cx='640' cy='440' r='1.5'/><circle cx='1000' cy='420' r='1.5'/><circle cx='1160' cy='540' r='1.5'/><circle cx='1320' cy='460' r='1.5'/><circle cx='220' cy='480' r='1.5'/><circle cx='400' cy='540' r='1.5'/><circle cx='580' cy='620' r='1.5'/><circle cx='760' cy='660' r='1.5'/><circle cx='940' cy='720' r='1.5'/><circle cx='1100' cy='780' r='1.5'/><circle cx='300' cy='680' r='1.5'/><circle cx='480' cy='760' r='1.5'/><circle cx='660' cy='820' r='1.5'/><circle cx='1440' cy='620' r='1.5'/></g><g fill='%23e5a900' fill-opacity='0.5'><circle cx='560' cy='300' r='2.5'/><circle cx='820' cy='480' r='2.5'/><circle cx='1280' cy='720' r='2.5'/></g><g stroke='%23e5a900' stroke-width='0.5' stroke-opacity='0.32' fill='none'><circle cx='560' cy='300' r='7'/><circle cx='820' cy='480' r='8'/><circle cx='1280' cy='720' r='7'/></g></svg>")
                center center / cover no-repeat fixed,
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
