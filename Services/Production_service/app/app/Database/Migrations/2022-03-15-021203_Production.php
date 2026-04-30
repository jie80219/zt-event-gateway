<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Production extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'p_key'           => [
                'type'           => 'INT',
                'constraint'     => 5,
                'unsigned'       => TRUE,
                'auto_increment' => TRUE
            ],
            'name'         => [
                'type'           => 'varchar',
                'constraint'     => 256,
                'null'           => false
            ],
            'description'       => [
                'type'           => 'text',
                'null'           => true
            ],
            'price'           => [
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
        $this->forge->addKey('p_key', TRUE);
        $this->forge->createTable('production');
    }

    public function down()
    {
        //
    }
}
