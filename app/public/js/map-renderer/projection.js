// Geometry helpers — bbox, viewport fit, simple zoom math.
//
// The provider already projected systems to 2D PHP-side, so this file
// only handles screen-space transforms (fitting the bbox to the SVG,
// label virtualization checks, etc.).

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
