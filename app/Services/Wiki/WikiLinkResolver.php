<?php

namespace App\Services\Wiki;

use App\Models\WikiPage;

class WikiLinkResolver
{
    public function resolve(string $slug, ?int $clientId): ?WikiPage
    {
        if ($clientId !== null) {
            $clientPage = WikiPage::active()->forClient($clientId)->where('slug', $slug)->first();
            if ($clientPage) {
                return $clientPage;
            }
        }

        return WikiPage::active()->globalScope()->where('slug', $slug)->first();
    }
}
