"""theater_clustering — group killmails into battle theaters.

See docs/adr/0006-battle-theater-reports.md for the design. This module
is pure data flow:

    killmails → clusterer → battle_theaters + child tables

The clusterer is a union-find over (constellation, 45-min proximity).
A full rebuild of UNLOCKED theaters on every pass keeps state pure;
LOCKED theaters (older than 48h) are excluded from the candidate pool
and never mutated.
"""
