<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddKeycloakSubToUsers extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'keycloak_sub' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'email',
            ],
        ]);
        $this->db->query('CREATE UNIQUE INDEX users_keycloak_sub_uidx ON users (keycloak_sub) WHERE keycloak_sub IS NOT NULL');
    }

    public function down()
    {
        $this->db->query('DROP INDEX IF EXISTS users_keycloak_sub_uidx');
        $this->forge->dropColumn('users', 'keycloak_sub');
    }
}
