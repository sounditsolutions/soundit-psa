<?php

namespace App\Enums;

/**
 * The autonomy tier of a resolved Technician action (spec §3/§7).
 *
 *  Auto    — safe/reversible/draft: executes through the gate without a human.
 *  Approve — client-facing or state-changing: held for a signed human approval.
 *  Block   — never: server denylist, refused outright.
 *
 * The gate classifies SERVER-SIDE on the resolved action and default-denies:
 * anything not explicitly mapped to Auto is treated as ≥Approve.
 */
enum TechnicianTier: string
{
    case Auto = 'auto';
    case Approve = 'approve';
    case Block = 'block';
}
