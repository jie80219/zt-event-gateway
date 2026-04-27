<?php

namespace App\Models\v1;

use CodeIgniter\Model;
use App\Entities\v1\ProductionEntity;

class ProductionModel extends Model
{
    protected $DBGroup          = USE_DB_GROUP;
    protected $table            = 'production';
    protected $primaryKey       = 'p_key';
    protected $useAutoIncrement = true;
    protected $insertID         = 0;
    protected $returnType       = ProductionEntity::class;
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = ['p_key','name','description','price'];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    // Validation
    protected $validationRules      = [];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    // Callbacks
    protected $allowCallbacks = true;
    protected $beforeInsert   = [];
    protected $afterInsert    = [];
    protected $beforeUpdate   = [];
    protected $afterUpdate    = [];
    protected $beforeFind     = [];
    protected $afterFind      = [];
    protected $beforeDelete   = [];
    protected $afterDelete    = [];

    /**
     * 新增商品與庫存的 transcation
     *
     * @param string $name
     * @param string $description
     * @param integer $price
     * @param integer $amount
     * @return int|null 新增的商品 id, 失敗回傳 null
     */
    public function createProductionTranscation(string $name, string $description, int $price, int $amount):?int
    {
        $productionData = [
            "name" => $name,
            "description" => $description,
            "price" => $price,
            "created_at" => date("Y-m-d H:i:s") ,
            "updated_at" => date("Y-m-d H:i:s")
        ];

        try{
            $this->db->transStart();

            $this->db->table("production")
                     ->insert($productionData);

            $productionInsertId = $this->db->insertID();

            $inventory = [
                "p_key" => $productionInsertId,
                "amount" => $amount,
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ];

            $this->db->table("inventory")
                     ->insert($inventory);

            $result = $this->db->transComplete();
            
            if ($result === false) {
                return null;
            }
            
            return $productionInsertId;
        }catch(\Exception $e){
            log_message('error', '[ERROR] {exception}', ['exception' => $e]);
            return false;
        }
    }
}
