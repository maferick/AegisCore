# Vendored D3 v7

We ship D3 from disk rather than a CDN — same stance as the rest of the
phase-1 frontend in `landing.blade.php`. No Vite, no npm, no CSP holes
to a third party.

## File

- `d3.v7.min.js` — D3 v7.9.0 UMD bundle (Mike Bostock + contributors).
- License: BSD 3-Clause (see https://github.com/d3/d3/blob/main/LICENSE).

## Provenance

```
upstream:  https://d3js.org/d3.v7.min.js
fetched:   2026-04-14
sha256:    f2094bbf6141b359722c4fe454eb6c4b0f0e42cc10cc7af921fc158fceb86539
size:      279706 bytes
```

To refresh on a future D3 release, re-download the file, update the
hash + date above, and bump the script reference in
`app/resources/views/components/map/renderer.blade.php`.

## Why UMD instead of ESM modules?

The renderer loads D3 once per page via a plain `<script>` tag — UMD
self-registers as `window.d3`, which is what `index.js` reads. ESM
modules would require an import map and complicate the no-build stance.
If we adopt Vite later, swap to `d3` imports and drop this file.
