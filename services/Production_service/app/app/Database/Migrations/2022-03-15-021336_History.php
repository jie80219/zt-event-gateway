<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class History extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'h_key'           => [
                'type'           => 'INT',
                'constraint'     => 5,
                'unsigned'       => TRUE,
                'auto_increment' => TRUE
            ],
            'p_key'           => [
                'type'           => 'INT',
                'constraint'     => 5,
                'unsigned'       => TRUE
            ],
            'o_key'           => [
                'type'           => 'VARCHAR',
                'constraint'     => 200
            ],
            'amount'           => [
                'type'           => 'INT',
                'constraint'     => 5,
                'unsigned'       => TRUE
            ],
            'type'           => [
                'type'           => 'varchar',
                'constraint'     => 256,
                'null'           => false
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
        $this->forge->addKey('h_key', TRUE);
        $this->forge->addForeignKey('p_key','production','p_key','RESTRICT','CASCADE');
        $this->forge->createTable('history');
    }

    public function down()
    {
        //
    }
}
