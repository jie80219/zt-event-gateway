<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use Bezhanov\Faker\Provider\Commerce;
use App\Database\Seeds\Inventory;
use App\Database\Seeds\History;

class Production extends Seeder
{
    public function __construct()
    {
        helper('date');
    }

    public function run()
    {
        $db      = \Config\Database::connect();
        $builder = $db->table("production");

        $faker = \Faker\Factory::create();
        $faker->addProvider(new Commerce($faker));

        $randomArr = [true,false]; 

        for ($i=0; $i < 100; $i++) {
            $builder->insert([
                "name" => $faker->productName(),
                "description" => $faker->text(),
                "price" => random_int(1,10000),
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ]);

            $p_key = $db->insertID();

            Inventory::insertInventory($p_key);

            History::insertHistory($p_key, $randomArr[random_int(0,1)]);
        }
    }
}
