<?php

declare(strict_types=1);

namespace App\System;

/**
 * Immutable snapshot of a single backend's health at the moment it was
 * checked. Rendered as one card in the admin System Status widget.
 *
 * Keep the payload small: `name` + `level` + `detail` is enough for the
 * three-line card. Anything richer (metrics, recent errors) belongs in
 * the dedicated monitoring UIs (Horizon, OpenSearch Dashboards).
 */
final readonly class SystemStatus
{
    public function __construct(
        public string $name,
        public SystemStatusLevel $level,
        public ?string $detail = null,
    ) {}
}
