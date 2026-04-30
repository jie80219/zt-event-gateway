<?php

namespace App\Database\Migrations;

use App\Database\Migrations\Order;
use App\Database\Migrations\OrderProduct;

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
        (new Order(\Config\Database::forge($group)))->up();
        (new OrderProduct(\Config\Database::forge($group)))->up();
    }
}
