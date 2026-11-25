<?php

namespace App\Support;

use Illuminate\Support\Str;

final class References
{
    public static function make(string $prefix): string
    {
        return strtoupper($prefix.'-'.now()->format('ymd').'-'.Str::upper(Str::random(6)));
    }

    public static function slug(string $title): string
    {
        return Str::slug($title).'-'.Str::lower(Str::random(4));
    }
}
