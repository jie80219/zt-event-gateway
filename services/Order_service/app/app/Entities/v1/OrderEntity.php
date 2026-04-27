<?php

namespace App\Entities\v1;

use CodeIgniter\Entity\Entity;

class OrderEntity extends Entity
{
    /**
     * 訂單主鍵
     *
     * @var string
     */
    protected $o_key;

    /**
     * 使用者外來鍵
     *
     * @var int
     */
    protected $u_key;

    /**
     * 折扣
     *
     * @var int
     */
    protected $discount;

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
        'o_key' => 'string'
    ];

    protected $dates = []; 
}
