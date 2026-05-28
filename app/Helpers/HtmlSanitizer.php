<?php

namespace App\Helpers;

use HTMLPurifier;
use HTMLPurifier_Config;

class HtmlSanitizer
{
    private static ?HTMLPurifier $purifier = null;

    public static function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        if (self::$purifier === null) {
            $config = HTMLPurifier_Config::createDefault();
            $config->set('HTML.Allowed', 'p,br,strong,b,em,i,u,a[href],img[src|alt|width|height],ul,ol,li,table,tr,td,th,thead,tbody,h1,h2,h3,h4,h5,h6,blockquote,pre,code,span[style],div');
            $config->set('CSS.AllowedProperties', 'color,background-color,font-weight,font-style,text-decoration,text-align');
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'cid' => true]);
            $config->set('Cache.SerializerPath', storage_path('app/htmlpurifier'));
            self::$purifier = new HTMLPurifier($config);
        }

        return self::$purifier->purify($html);
    }
}
