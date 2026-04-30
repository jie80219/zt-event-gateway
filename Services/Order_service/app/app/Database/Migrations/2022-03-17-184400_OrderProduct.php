<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class OrderProduct extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'o_key'           => [
                'type'           => 'VARCHAR',
                'constraint'     => 200,
            ],
            'p_key'         => [
                'type'           => 'INT',
                'constraint'     => 5,
                'unsigned'       => TRUE
            ],
            'price'           => [
                'type'           => 'INT',
                'constraint'     => 5,
                'unsigned'       => TRUE
            ],
            'amount'           => [
                'type'           => 'INT',
                'constraint'     => 5,
                'unsigned'       => TRUE
            ],
            'ext_price'           => [
                'type'           => 'INT',
                'constraint'     => 5,
                'unsigned'       => TRUE
            ],
            "created_at"    => [
                'type'           => 'datetime'
            ],
            "updated_at"    => [
                'type'           => 'datetime'
            ],
            "deleted_at"    => [
				'type'           => 'datetime',
				'null'           => true
			]
        ]);
        $this->forge->addForeignKey('o_key','order','o_key','RESTRICT','CASCADE');
        $this->forge->createTable('order_product');
    }

    public function down()
    {
        //
    }
}
