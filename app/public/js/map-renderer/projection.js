// Geometry helpers — bbox, viewport fit, simple zoom math.
//
// The provider already projected systems to 2D PHP-side, so this file
// only handles screen-space transforms (fitting the bbox to the SVG,
// label virtualization checks, etc.).

// Rescale data-space coordinates into a pixel-like canvas.
//
// EVE SDE positions arrive as metres at astronomical scale (region
// centroids on the order of 1e17, bbox spans around 1e18). The
// renderer's per-node metrics — circle radii (3..14), label font-size
// (9), default stroke widths — assume that one data unit maps to
// roughly one pixel at the default fit. Without this pass,
// `fitToViewport` would produce a scale around 1e-15, pushing every
// radius / font-size / stroke below a single pixel (the reason the
// map appears as an empty box), and landing the initial zoom well
// below `d3.zoom().scaleExtent([0.2, 12])`, which then clamps to 0.2
// the instant the user touches the wheel.
//
// We centre the bbox around the origin and scale so the larger axis
// fills `targetSpan`, keeping aspect ratio intact. `nodeData` is
// mutated in place so the node -> edge index set up by the renderer
// stays coherent (edges reference node objects, not copies). Returns
// the bbox in the new coordinate space.
export function rescaleToPixelCanvas(nodeData, bbox, targetSpan) {
    if (!bbox || bbox.length !== 4 || !Array.isArray(nodeData) || nodeData.length === 0) {
        return Array.isArray(bbox) ? bbox.slice() : [0, 0, 0, 0];
    }

    const [minX, minY, maxX, maxY] = bbox;
    const spanX = maxX - minX;
    const spanY = maxY - minY;
    const span = Math.max(spanX, spanY);
    const cx = (minX + maxX) / 2;
    const cy = (minY + maxY) / 2;

    // Degenerate bbox (all points coincident). Recentre on the origin
    // and hand back a synthetic bbox the size of the target canvas so
    // fitToViewport lands on a scale near 1.0 instead of the huge
    // fallback it produces for sub-1e-9 extents (which would blow up
    // circle radii / label metrics).
    if (!(span > 0)) {
        for (const n of nodeData) {
            n.x = 0;
            n.y = 0;
        }
        const half = targetSpan / 2;
        return [-half, -half, half, half];
    }

    const k = targetSpan / span;

    for (const n of nodeData) {
        n.x = (n.x - cx) * k;
        n.y = (n.y - cy) * k;
    }

    return [
        (minX - cx) * k,
        (minY - cy) * k,
        (maxX - cx) * k,
        (maxY - cy) * k,
    ];
}

export function fitToViewport(bbox, width, height, paddingPx = 24) {
    if (!bbox || bbox.length !== 4) {
        return { tx: 0, ty: 0, scale: 1 };
    }
    const [minX, minY, maxX, maxY] = bbox;
    const dataW = Math.max(1e-9, maxX - minX);
    const dataH = Math.max(1e-9, maxY - minY);
    const innerW = Math.max(1, width - paddingPx * 2);
    const innerH = Math.max(1, height - paddingPx * 2);
    const scale = Math.min(innerW / dataW, innerH / dataH);
    // Centre the data bbox inside the SVG.
    const cx = (minX + maxX) / 2;
    const cy = (minY + maxY) / 2;
    const tx = width / 2 - cx * scale;
    const ty = height / 2 - cy * scale;
    return { tx, ty, scale };
}

// Test whether (x, y) — in data coordinates — falls inside the visible
// viewport given a d3.zoom transform. Used by the label virtualization
// pass so we don't render 8000 <text> elements when the user zooms out.
export function isVisible(x, y, transform, width, height, marginPx = 0) {
    const sx = transform.applyX(x);
    const sy = transform.applyY(y);
    return sx >= -marginPx && sx <= width + marginPx
        && sy >= -marginPx && sy <= height + marginPx;
}
