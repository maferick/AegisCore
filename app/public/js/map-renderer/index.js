// Entry point — wires a root <div> to the D3 renderer.
//
// Public API: `mountMapRenderer(rootEl, optionsOverride?)`. The Blade
// component calls this once per instance with the root element it
// emitted. All configuration is read from `data-*` attributes so the
// component can be used from arbitrary Blade contexts without a
// JS-side registry.

import { render } from './render.js';

export async function mountMapRenderer(rootEl, optionsOverride) {
    if (!rootEl) {
        console.warn('[map-renderer] mount called with no root element');
        return;
    }

    const options = readOptions(rootEl, optionsOverride);

    setStatus(rootEl, 'loading', 'Loading map…');

    try {
        const payload = await fetchPayload(options.url);
        setStatus(rootEl, '', '');
        render(rootEl, payload, options);
    } catch (err) {
        console.error('[map-renderer] load failed', err);
        setStatus(rootEl, 'error', 'Failed to load map: ' + (err?.message || err));
    }
}

function readOptions(rootEl, override) {
    const ds = rootEl.dataset;
    let highlights = [];
    if (ds.highlights) {
        try {
            highlights = JSON.parse(ds.highlights);
        } catch (e) {
            highlights = [];
        }
    }

    return Object.assign({
        url: ds.url,
        scope: ds.scope || 'universe',
        labelMode: ds.labelMode || 'hover',
        colorBy: ds.colorBy || 'security',
        interactive: ds.interactive !== 'false',
        highlights,
        instanceId: ds.instanceId || rootEl.id || 'map_default',
    }, override || {});
}

async function fetchPayload(url) {
    if (!url) {
        throw new Error('no data-url on map root element');
    }
    const res = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
    });
    if (!res.ok) {
        const body = await res.text().catch(() => '');
        throw new Error(`HTTP ${res.status}${body ? `: ${body.slice(0, 200)}` : ''}`);
    }
    return res.json();
}

function setStatus(rootEl, kind, message) {
    let status = rootEl.querySelector('.aegis-map-status');
    if (!status) {
        status = document.createElement('div');
        status.className = 'aegis-map-status';
        rootEl.appendChild(status);
    }
    if (!message) {
        status.remove();
        return;
    }
    status.dataset.kind = kind;
    status.textContent = message;
}

// Auto-mount any root that's already in the DOM at import time. Lets
// callers get away with just dropping the script tag — Blade
// components still mount explicitly via `mountMapRenderer(...)` so
// double mounts are avoided via the `data-mounted` flag.
function autoMount() {
    const roots = document.querySelectorAll('.aegis-map-root');
    roots.forEach((el) => {
        if (el.dataset.mounted === 'true') return;
        el.dataset.mounted = 'true';
        mountMapRenderer(el);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoMount, { once: true });
} else {
    autoMount();
}

// Expose on window for debugging from the browser console.
if (typeof window !== 'undefined') {
    window.AegisMap = { mount: mountMapRenderer };
}
