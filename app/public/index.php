<?php
declare(strict_types=1);

// AegisCore — Phase 1 stub front controller.
// Replace once the real PHP control plane is wired up. Shape follows the
// success envelope defined in docs/CONTRACTS.md: { data, meta }.

header('Content-Type: application/json');
header('X-AegisCore-Phase: 1');

$payload = [
    'data' => [
        'name'   => 'AegisCore',
        'phase'  => 1,
        'status' => 'bootstrap',
        'env'    => getenv('AEGISCORE_ENV') ?: 'unknown',
    ],
    'meta' => [
        'timestamp' => gmdate('c'),
        'php'       => PHP_VERSION,
    ],
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
