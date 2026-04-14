// D3 render pipeline.
//
// The renderer assumes `window.d3` is already loaded (the Blade
// component injects the vendored UMD bundle before this module). We
// keep the DOM additive so multiple instances on the same page don't
// fight: every selector is rooted at `rootEl`, every internal id is
// prefixed with `instanceId`.
//
// SVG layer order (back to front):
//   1. <g class="bg">      — backdrop / starfield / dim wash
//   2. <g class="edges">   — stargate / cluster edges
//   3. <g class="nodes">   — system / region dots
//   4. <g class="labels">  — virtualised text labels
//   5. <g class="overlay"> — highlights, route, hover ring
//
// Z-order matters because edges look terrible drawn on top of nodes.

import { securityColor, regionColor, edgeColor } from './color.js';
import { fitToViewport, isVisible } from './projection.js';

const D3 = () => window.d3;

const HUD = {
    accent: '#4fd0d0',
    accentDim: '#3aa8a8',
    gold: '#e5a900',
    danger: '#ff3838',
    nodeStroke: 'rgba(8, 12, 18, 0.85)',
    edgeStroke: 'rgba(120, 130, 140, 0.45)',
};

const LABEL_ZOOM_THRESHOLD = 1.6;
const REGION_LABEL_ZOOM = 0;       // always show region labels in universe-aggregated
const DENSE_NODE_ZOOM = 1.2;       // below this zoom we collapse to <rect> dots

export function render(rootEl, payload, opts) {
    const d3 = D3();
    if (!d3) {
        renderError(rootEl, 'D3 is not loaded — check /vendor/d3/d3.v7.min.js.');
        return;
    }

    const instanceId = opts.instanceId;
    const labelMode = opts.labelMode || 'hover';
    const colorBy = opts.colorBy || 'security';
    const interactive = opts.interactive !== false;
    const highlights = new Set((opts.highlights || []).map((v) => Number(v)));

    rootEl.innerHTML = '';

    const { width, height } = sizeOf(rootEl);

    const svg = d3.select(rootEl)
        .append('svg')
        .attr('class', 'aegis-map-svg')
        .attr('viewBox', `0 0 ${width} ${height}`)
        .attr('preserveAspectRatio', 'xMidYMid meet')
        .attr('width', '100%')
        .attr('height', '100%')
        .attr('role', 'img')
        .attr('aria-label', captionFor(payload));

    // Backdrop — subtle starfield emulation (random dots), cheap to
    // draw and keeps the viewport from feeling empty when zoomed in.
    const bg = svg.append('g').attr('class', 'bg');
    bg.append('rect')
        .attr('x', 0).attr('y', 0)
        .attr('width', width).attr('height', height)
        .attr('fill', 'url(#aegis-map-bg-' + instanceId + ')');

    const defs = svg.append('defs');
    const grad = defs.append('radialGradient').attr('id', 'aegis-map-bg-' + instanceId);
    grad.append('stop').attr('offset', '0%').attr('stop-color', '#0a141d');
    grad.append('stop').attr('offset', '100%').attr('stop-color', '#000308');

    const root = svg.append('g').attr('class', 'zoom-layer');
    const edgesG = root.append('g').attr('class', 'edges');
    const nodesG = root.append('g').attr('class', 'nodes');
    const labelsG = root.append('g').attr('class', 'labels');
    const overlayG = root.append('g').attr('class', 'overlay');

    // Build node + edge data depending on what the payload contains.
    // Universe-aggregated: regions are the nodes, region edges are
    // the edges. All other scopes: systems + jumps.
    const isAggregatedUniverse = payload.scope === 'universe' && (payload.systems?.length ?? 0) === 0;
    const nodeData = isAggregatedUniverse
        ? (payload.regions || []).map((r) => ({
            id: r.id, name: r.name, x: r.x, y: r.y,
            kind: 'region',
            radius: clamp(2 + Math.log10(Math.max(1, r.systemCount)) * 4, 3, 14),
            color: regionColor(r.id),
            sec: null,
        }))
        : (payload.systems || []).map((s) => ({
            id: s.id, name: s.name, x: s.x, y: s.y,
            kind: 'system',
            radius: s.hub ? 4.5 : (s.stationsCount > 0 ? 3.2 : 2.6),
            color: colorBy === 'region' ? regionColor(s.regionId) : securityColor(s.securityStatus),
            sec: s.securityStatus,
            stations: s.stationsCount || 0,
            hub: !!s.hub,
            regionId: s.regionId,
        }));

    const nodeIndex = new Map(nodeData.map((n) => [n.id, n]));

    const edgeData = (payload.jumps || [])
        .map((j) => ({
            a: j.a, b: j.b, kind: j.kind,
            from: nodeIndex.get(j.a),
            to: nodeIndex.get(j.b),
        }))
        .filter((e) => e.from && e.to);

    // Initial fit: choose a transform that frames the bbox.
    const initial = fitToViewport(payload.bbox, width, height, 32);
    const initialT = d3.zoomIdentity.translate(initial.tx, initial.ty).scale(initial.scale);

    // Edges as <line>. Width scales mildly with zoom so they don't
    // disappear when zoomed out too far.
    const edgeSel = edgesG.selectAll('line')
        .data(edgeData)
        .enter().append('line')
        .attr('x1', (d) => d.from.x)
        .attr('y1', (d) => d.from.y)
        .attr('x2', (d) => d.to.x)
        .attr('y2', (d) => d.to.y)
        .attr('stroke', (d) => isAggregatedUniverse
            ? 'rgba(79, 208, 208, 0.18)'
            : edgeColor(d.from.sec, d.to.sec))
        .attr('stroke-width', isAggregatedUniverse ? 0.6 : 0.4)
        .attr('vector-effect', 'non-scaling-stroke')
        .attr('opacity', 0.85);

    // Nodes as <circle>. We keep one element per node and resize via
    // the zoom transform so we don't have to rebuild the DOM on pan.
    const nodeSel = nodesG.selectAll('circle')
        .data(nodeData, (d) => d.id)
        .enter().append('circle')
        .attr('cx', (d) => d.x)
        .attr('cy', (d) => d.y)
        .attr('r', (d) => d.radius)
        .attr('fill', (d) => d.color)
        .attr('stroke', (d) => highlights.has(d.id) ? HUD.gold : HUD.nodeStroke)
        .attr('stroke-width', (d) => highlights.has(d.id) ? 1.8 : 0.6)
        .attr('vector-effect', 'non-scaling-stroke')
        .attr('class', (d) => 'node node-' + d.kind + (d.hub ? ' node-hub' : ''))
        .attr('data-id', (d) => d.id);

    if (interactive) {
        nodeSel
            .style('cursor', 'pointer')
            .append('title')
            .text((d) => labelText(d, payload));
    }

    // Initial labels — only the always-visible ones get pre-rendered.
    // Hover labels are added on demand by the mouseover handler so we
    // don't ship 8000 <text> nodes for the universe-dense view.
    let labelSel = labelsG.selectAll('text').data([], (d) => d.id);

    const drawLabels = (transform) => {
        const visible = nodeData.filter((n) => {
            if (labelMode === 'always') return true;
            if (n.kind === 'region') return transform.k >= REGION_LABEL_ZOOM;
            if (transform.k < LABEL_ZOOM_THRESHOLD) return false;
            return isVisible(n.x, n.y, transform, width, height, 32);
        });

        labelSel = labelsG.selectAll('text').data(visible, (d) => d.id);
        labelSel.exit().remove();
        labelSel.enter().append('text')
            .attr('class', 'label')
            .attr('x', (d) => d.x)
            .attr('y', (d) => d.y)
            .attr('dy', (d) => -d.radius - 3)
            .attr('text-anchor', 'middle')
            .attr('fill', HUD.accent)
            .attr('font-size', 9)
            .attr('paint-order', 'stroke')
            .attr('stroke', 'rgba(0,0,0,0.7)')
            .attr('stroke-width', 2)
            .style('pointer-events', 'none')
            .text((d) => d.name);
    };

    // d3.zoom — pan + wheel-zoom. We translate the root <g> rather
    // than rebuilding the SVG every frame.
    const zoom = d3.zoom()
        .scaleExtent([0.2, 12])
        .on('zoom', (ev) => {
            root.attr('transform', ev.transform);
            drawLabels(ev.transform);
        });

    if (interactive) {
        svg.call(zoom);
    }

    svg.call(zoom.transform, initialT);

    // Caption + scope chip overlay (always-on HUD furniture).
    drawHud(rootEl, payload);
}

function captionFor(payload) {
    switch (payload.scope) {
        case 'universe': return 'New Eden universe map';
        case 'region': return 'Region map';
        case 'constellation': return 'Constellation map';
        case 'subgraph': return 'System subgraph map';
        default: return 'EVE map';
    }
}

function labelText(node, payload) {
    if (node.kind === 'region') {
        return `${node.name}\nregion · ${node.id}`;
    }
    const lines = [node.name];
    if (node.sec !== null && node.sec !== undefined) {
        lines.push(`sec ${node.sec.toFixed(2)}`);
    }
    if (node.stations) {
        lines.push(`${node.stations} station${node.stations === 1 ? '' : 's'}`);
    }
    return lines.join('\n');
}

function drawHud(rootEl, payload) {
    const d3 = D3();
    const hud = d3.select(rootEl).append('div').attr('class', 'aegis-map-hud');
    const counts = [];
    if (payload.systems?.length) counts.push(`${payload.systems.length} systems`);
    if (payload.regions?.length) counts.push(`${payload.regions.length} regions`);
    if (payload.jumps?.length) counts.push(`${payload.jumps.length} jumps`);
    hud.html(`
        <span class="aegis-map-hud-scope">${escapeHtml(payload.scope)}</span>
        <span class="aegis-map-hud-counts">${escapeHtml(counts.join(' · '))}</span>
        ${payload.buildNumber ? `<span class="aegis-map-hud-build">SDE ${payload.buildNumber}</span>` : ''}
    `);
}

function renderError(rootEl, message) {
    rootEl.innerHTML = '';
    const el = document.createElement('div');
    el.className = 'aegis-map-error';
    el.textContent = message;
    rootEl.appendChild(el);
}

function sizeOf(el) {
    const rect = el.getBoundingClientRect();
    return {
        width: Math.max(120, Math.round(rect.width || 800)),
        height: Math.max(120, Math.round(rect.height || 480)),
    };
}

function clamp(v, lo, hi) {
    return Math.max(lo, Math.min(hi, v));
}

function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}
