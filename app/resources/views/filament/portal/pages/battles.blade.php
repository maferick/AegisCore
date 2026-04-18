<x-filament-panels::page>
    <style>
        /* Tint rows where the viewer's corp / alliance / bloc actually
           fielded pilots. Uses a mid-saturation blue so it reads as
           "you were in this one" without overpowering the table header. */
        .bt-involved-row,
        .bt-involved-row td {
            background-color: rgba(59, 130, 246, 0.14) !important;
        }
        .bt-involved-row:hover,
        .bt-involved-row:hover td {
            background-color: rgba(59, 130, 246, 0.22) !important;
        }
    </style>
    {{ $this->table }}
</x-filament-panels::page>
