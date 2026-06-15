<?php

namespace App\Enums;

enum WikiRunType: string
{
    case MineTicket = 'mine_ticket';
    case SyncFacts = 'sync_facts';
    case Maintain = 'maintain';
    case Backfill = 'backfill';
    case Compose = 'compose';
    case DraftResolution = 'draft_resolution';
}
