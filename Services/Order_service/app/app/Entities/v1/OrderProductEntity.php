<?php

namespace App\Entities\v1;

use CodeIgniter\Entity\Entity;

class OrderProductEntity extends Entity
{
    /**
     * 訂單主鍵
     *
     * @var string
     */
    protected $o_key;

    /**
     * 產品外來鍵
     *
     * @var int
     */
    protected $p_key;

    /**
     * 產品當下價錢
     *
     * @var int
     */
    protected $price;

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
        'o_key' => 'string',
        'p_key' => 'integer'
    ];

    protected $dates = []; 
}
