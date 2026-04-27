<?php

namespace App\Models\v1;

use CodeIgniter\Model;
use App\Entities\v1\OrderProductEntity;

class OrderProductModel extends Model
{
    protected $DBGroup          = USE_DB_GROUP;
    protected $table            = 'order_product';
    protected $primaryKey       = array('o_key','p_key');
    protected $useAutoIncrement = true;
    protected $insertID         = 0;
    protected $returnType       = OrderProductEntity::class;
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = ['o_key','p_key','price', 'amount', 'ext_price'];

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
}
