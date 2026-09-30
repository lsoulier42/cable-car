<?php

namespace App\Helper;

use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Small wrapper around Symfony's AsciiSlugger for manual slug generation.
 */
final class SluggerHelper
{
    private function __construct()
    {
    }

    public static function slugify(string $value, string $separator = '-'): string
    {
        return (new AsciiSlugger())
            ->slug($value, $separator)
            ->lower()
            ->toString();
    }
}
