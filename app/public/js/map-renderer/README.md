# `/js/map-renderer/`

Frontend half of the EVE map renderer module. Pure ESM, no build
step; the Blade component (`<x-map.renderer .../>`) injects a
`<script type="module">` that imports `index.js`.

## Files

| File             | Purpose                                                        |
|------------------|----------------------------------------------------------------|
| `index.js`       | Entry — fetches JSON, calls `render()`. Auto-mounts roots.     |
| `render.js`      | D3 SVG pipeline: edges, nodes, labels, zoom, HUD overlay.      |
| `projection.js`  | Viewport math — `fitToViewport`, `isVisible` (label virt.).    |
| `color.js`       | Security colour scale + deterministic region hash colour.      |
| `styles.css`     | Scoped under `.aegis-map-root`. EVE HUD palette.               |

## Mount contract

The Blade component emits a `<div class="aegis-map-root">` with these
data attributes:

```
data-url            full JSON endpoint URL (built by the controller)
data-scope          universe | region | constellation | subgraph
data-label-mode     hover | always | hidden
data-color-by       security | region
data-interactive    "true" | "false"   (controls pan/zoom + tooltips)
data-highlights     JSON array of system / region IDs to ring in gold
data-instance-id    DOM-unique key; used to namespace internal IDs
```

`mountMapRenderer(rootEl, override?)` is exported so callers can bind
manually. The auto-mount on import handles the common case; the
`data-mounted="true"` flag prevents double mounts when a script tag
re-imports the module.

## Layer order

```
<g class="bg">     -- backdrop / starfield gradient
<g class="zoom-layer">
  <g class="edges">    -- jump lines
  <g class="nodes">    -- circles
  <g class="labels">   -- virtualised <text>
  <g class="overlay">  -- highlight rings, route arrows
```

Stargate edges are drawn beneath nodes deliberately; rendering them
on top makes hub systems unreadable.

## Performance notes

- Universe-dense (~8000 systems) ships ~280 KB JSON. We render every
  system as a `<circle>` and rely on `vector-effect: non-scaling-stroke`
  to keep stroke widths sane under zoom.
- Labels are virtualised: only systems inside the viewport at zoom
  ≥ `LABEL_ZOOM_THRESHOLD` are rendered. Region-aggregated view shows
  region labels at all zooms (only ~113 of them).
- The fetch is `same-origin` only; the public endpoint sets no CORS
  headers because this renderer always runs on the same host.

## Adding overlays (phase 2)

Phase-2 overlays (kill heatmap, jump-bridge graph, fleet pings) plug
into `overlay` group via a small registry inside `render.js`. The
shape is `register('kills', (svg, data) => { ... })`; the renderer
will call registered overlays after the main pipeline finishes, so
they can read computed positions off the node selection.
