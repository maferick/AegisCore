{{-- Shared EVE-themed background for killsineve.online public pages.
     Pure CSS — no images, no extra HTTP, no broken-image risk. Layers
     a deep-space starfield, three nebula clouds (cyan / purple / amber),
     and a faint horizon glow. Fixed attachment so it slides under
     content like a parallax backdrop. Per-page accent (--page-accent)
     can be tinted by the page's <body data-page="..."> attribute. --}}
<style>
    :root {
        --aegis-bg-base: #03060a;
        --aegis-bg-deep: #050709;
        --page-accent: rgba(79, 208, 208, 0.18);
    }
    body[data-page="war-report"]   { --page-accent: rgba(134, 239, 172, 0.16); }
    body[data-page="vs-imperium"]  { --page-accent: rgba(252, 165, 165, 0.18); }
    body[data-page="vs-initiative"]{ --page-accent: rgba(253, 186, 116, 0.18); }
    body[data-page="battles"]      { --page-accent: rgba(253, 224, 71, 0.14); }
    body[data-page="kill"]         { --page-accent: rgba(239, 68, 68, 0.20); }

    body.aegis-public-bg {
        background-color: var(--aegis-bg-base);
        background-image:
            /* faint dotted starfield — two scales for depth */
            radial-gradient(rgba(255,255,255,0.085) 1px, transparent 1.2px),
            radial-gradient(rgba(255,255,255,0.045) 1px, transparent 1.4px),
            /* nebula clouds — six glowing volumes for proper space-y depth */
            radial-gradient(ellipse 70% 55% at  8% -8%,  rgba(99,  102, 241, 0.22) 0%, transparent 60%),
            radial-gradient(ellipse 80% 60% at 92% 108%, rgba(229, 169,   0, 0.14) 0%, transparent 60%),
            radial-gradient(ellipse 55% 45% at 75%  18%, rgba(168, 85,  247, 0.16) 0%, transparent 60%),
            radial-gradient(ellipse 50% 40% at 25%  78%, rgba( 14, 165, 233, 0.14) 0%, transparent 60%),
            radial-gradient(ellipse 35% 30% at 55%  52%, rgba(244,  63,  94, 0.10) 0%, transparent 60%),
            radial-gradient(ellipse 60% 50% at 50%  50%, var(--page-accent) 0%, transparent 65%),
            /* base deep-space gradient */
            linear-gradient(180deg, var(--aegis-bg-deep) 0%, var(--aegis-bg-base) 80%);
        background-size:
            48px 48px,
            96px 96px,
            auto, auto, auto, auto, auto, auto, auto;
        background-position:
            0 0,
            24px 24px,
            0 0, 0 0, 0 0, 0 0, 0 0, 0 0, 0 0;
        background-attachment: fixed;
    }

    /* A single subtle ship silhouette anchored bottom-right, only on
       wide viewports — keeps the page personal without crowding. The
       SVG is inline so there's no external request. */
    body.aegis-public-bg::after {
        content: '';
        position: fixed;
        right: -60px;
        bottom: -40px;
        width: 480px;
        height: 320px;
        opacity: 0.06;
        pointer-events: none;
        background: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 480 320'><g stroke='%23ffffff' stroke-width='1.2' fill='none' stroke-linejoin='round'><path d='M40 240 L100 200 L240 180 L360 200 L420 220 L420 250 L360 260 L240 260 L100 250 Z'/><path d='M120 200 L200 165 L320 165 L380 200'/><path d='M200 165 L220 130 L260 130 L280 165'/><path d='M40 240 L25 230 M40 240 L20 250'/><path d='M420 250 L450 245 M420 250 L455 260'/><circle cx='220' cy='148' r='3'/><circle cx='260' cy='148' r='3'/></g></svg>") no-repeat center / contain;
        z-index: 0;
    }
    @media (max-width: 900px) {
        body.aegis-public-bg::after { display: none; }
    }

    /* Conflict watermarks — two giant faint alliance/coalition logos
       anchored bottom-left + bottom-right on vs-imperium and
       vs-initiative pages. Pure decoration; pointer-events:none so
       they never intercept clicks. Logos pulled through our local
       /img cache so first-render is fast and CCP isn't hammered. */
    .aegis-watermarks {
        position: fixed;
        inset: 0;
        pointer-events: none;
        z-index: 0;
        overflow: hidden;
    }
    .aegis-watermark {
        position: absolute;
        width: 38vh;
        max-width: 360px;
        min-width: 200px;
        opacity: 0.06;
        filter: drop-shadow(0 0 24px rgba(255,255,255,0.10));
        transition: opacity 0.4s;
    }
    body[data-page="vs-imperium"]   .aegis-watermark.left  { left: -3vw;  bottom: 12vh; opacity: 0.07; }
    body[data-page="vs-imperium"]   .aegis-watermark.right { right: -3vw; bottom: 12vh; opacity: 0.06; }
    body[data-page="vs-initiative"] .aegis-watermark.left  { left: -3vw;  bottom: 12vh; opacity: 0.07; }
    body[data-page="vs-initiative"] .aegis-watermark.right { right: -3vw; bottom: 12vh; opacity: 0.06; }
    @media (max-width: 1100px) {
        .aegis-watermarks { display: none; }
    }
</style>
