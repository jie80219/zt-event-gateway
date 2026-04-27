<?php

namespace App\Entities\v1;

use CodeIgniter\Entity\Entity;

class InventoryEntity extends Entity
{
    /**
     * 商品外來鍵
     *
     * @var int
     */
    protected $p_key;

    /**
     * 數量
     *
     * @var string
     */
    protected $amount;

    /**
     * 建立時間
     *
     * @var string
     */
    protected $createdAt;

    /**
     * 最後更新時間
     *
     * @var string
     */
    protected $updatedAt;

    /**
     * 刪除時間
     *
     * @var string
     */
    protected $deletedAt;

    protected $datamap = [
        'createdAt' => 'created_at',
        'updatedAt' => 'updated_at',
        'deletedAt' => 'deleted_at'
    ];

    protected $casts = [
        'p_key' => 'integer'
    ];

    protected $dates = []; 
}
