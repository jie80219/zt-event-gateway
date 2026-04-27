<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Inventory extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'p_key'           => [
                'type'           => 'INT',
                'constraint'     => 5,
                'unsigned'       => TRUE
            ],
            'amount'           => [
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
        $this->forge->addForeignKey('p_key','production','p_key','RESTRICT','CASCADE');
        $this->forge->createTable('inventory');
    }

    public function down()
    {
        //
    }
}
