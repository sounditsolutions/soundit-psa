<?php

namespace App\Support;

use App\Models\Setting;

class WikiConfig
{
    // Spec §9: wiki_enabled defaults OFF. Further keys (wiki_auto_mine, budgets,
    // staleness windows) arrive with the phases that consume them — YAGNI here.
    public static function isEnabled(): bool
    {
        return (bool) Setting::getValue('wiki_enabled');
    }
}
