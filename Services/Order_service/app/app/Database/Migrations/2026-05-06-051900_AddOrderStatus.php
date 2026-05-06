<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddOrderStatus extends Migration
{
    public function up()
    {
        $this->forge->addColumn('order', [
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'default'    => 'pending',
                'null'       => false,
            ],
        ]);
        $this->db->query("UPDATE \"order\" SET status='completed' WHERE deleted_at IS NULL");
    }

    public function down()
    {
        $this->forge->dropColumn('order', 'status');
    }
}
