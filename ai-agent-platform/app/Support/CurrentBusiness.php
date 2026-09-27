<?php

namespace App\Support;

use App\Models\Business;

final class CurrentBusiness
{
    public static function id(): ?int
    {
        return app()->bound('currentBusinessId') ? (int) app('currentBusinessId') : null;
    }

    public static function get(): ?Business
    {
        $id = self::id();

        return $id ? Business::query()->find($id) : null;
    }

    public static function require(): Business
    {
        $business = self::get();

        abort_unless($business, 403, 'No shop selected.');

        return $business;
    }

    public static function set(?int $id): void
    {
        if ($id === null) {
            app()->forgetInstance('currentBusinessId');

            return;
        }

        app()->instance('currentBusinessId', $id);
    }
}
