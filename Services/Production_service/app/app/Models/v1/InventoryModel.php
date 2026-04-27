<?php

namespace App\Models\v1;

use CodeIgniter\Model;
use App\Entities\v1\InventoryEntity;

class InventoryModel extends Model
{
    protected $DBGroup          = USE_DB_GROUP;
    protected $table            = 'inventory';
    protected $primaryKey       = 'p_key';
    protected $useAutoIncrement = false;
    protected $insertID         = 0;
    protected $returnType       = InventoryEntity::class;
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = ['p_key','amount'];

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
     * 新增庫存與流水帳 transcation
     *
     * @param integer $p_key
     * @param string  $o_key
     * @param integer $addAmount
     * @param integer $nowAmount
     * @param string $type
     * @return boolean
     */
    public function addInventoryTranscation(int $p_key,string $o_key,int $addAmount,int $nowAmount,string $type):bool
    {
        $histotyData = [
            "p_key" => $p_key,
            "o_key" => $o_key,
            "amount" => $addAmount,
            "type" => $type,
            "created_at" => date("Y-m-d H:i:s"),
            "updated_at" => date("Y-m-d H:i:s")
        ];

        try {
            $this->db->transStart();

            $this->db->table("history")
                     ->insert($histotyData);

            $inventory = [
                "amount" => $nowAmount + $addAmount,
                "updated_at" => date("Y-m-d H:i:s")
            ];

            $this->db->table("inventory")
                        ->where("p_key", $p_key)
                        ->update($inventory);

            $result = $this->db->transComplete();
        } catch (\Exception $e) {
            log_message('error', '[ERROR] {exception}', ['exception' => $e]);
            return false;
        }
        return $result;
    }

    /**
     * 減少庫存數量與增加流水帳 transcation
     *
     * @param integer $p_key
     * @param string  $o_key
     * @param integer $reduceAmount
     * @param integer $nowAmount
     * @return boolean
     */
    public function reduceInventoryTranscation(int $p_key, string $o_key, int $reduceAmount, int $nowAmount):bool
    {
        $histotyData = [
            "p_key" => $p_key,
            "o_key" => $o_key,
            "amount" => $reduceAmount,
            "type" => "create",
            "created_at" => date("Y-m-d H:i:s"),
            "updated_at" => date("Y-m-d H:i:s")
        ];

        $hasCreate = $this->db->table("history")
                              ->where("p_key", $p_key)
                              ->where("o_key", $o_key)
                              ->where("type", "create")
                              ->get()
                              ->getFirstRow();
        $hasCompensate = $this->db->table("history")
                                  ->where("p_key", $p_key)
                                  ->where("o_key", $o_key)
                                  ->where("type", "compensate")
                                  ->get()
                                  ->getFirstRow();
        try {
            $this->db->transBegin();

            if($hasCreate && $hasCompensate){
                $this->db->table("history")
                          ->where("p_key", $p_key)
                          ->where("o_key", $o_key)
                          ->where("type", "create")
                          ->update(["deleted_at" => date("Y-m-d H:i:s")]);
                $this->db->table("history")
                         ->where("p_key", $p_key)
                         ->where("o_key", $o_key)
                         ->where("type", "compensate")
                         ->update(["deleted_at" => date("Y-m-d H:i:s")]);
            }

            $this->db->table("history")
                     ->insert($histotyData);

            $inventory = [
                "amount" => $nowAmount - $reduceAmount,
                "updated_at" => date("Y-m-d H:i:s")
            ];
            
            $this->db->table("inventory")
                     ->where("p_key", $p_key)
                     ->where("amount >=", $reduceAmount)
                     ->update($inventory);

            if($this->db->transStatus() === false || $this->db->affectedRows() == 0){
                $this->db->transRollback();
                return false;
            }else{
                $this->db->transCommit();
                return true;
            }

        } catch (\Exception $e) {
            log_message('error', '[ERROR] {exception}', ['exception' => $e]);
            return false;
        }
    }
}
