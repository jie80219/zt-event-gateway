<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Order extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'o_key'           => [
                'type'           => 'VARCHAR',
                'constraint'     => 200,
                'unique'         => true,
            ],
            'u_key'         => [
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
        $this->forge->addKey('o_key', TRUE);
        $this->forge->createTable('order');
    }

    public function down()
    {
        //
    }
}
