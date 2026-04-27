<?php

namespace App\Database\Migrations;

use App\Database\Migrations\Production;
use App\Database\Migrations\Inventory;
use App\Database\Migrations\History;

class initDataBase
{
    public static function initDataBase($group = "default")
    {
		\Config\Services::migrations()->setGroup($group);
        // self::createTable($group);
        // return "success";

    }

    public static function createTable($group)
    {
        (new Production(\Config\Database::forge($group)))->up();
        (new Inventory(\Config\Database::forge($group)))->up();
        (new History(\Config\Database::forge($group)))->up();
    }
}
