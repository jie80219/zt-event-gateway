<?php

namespace App\Entities\v1;

use CodeIgniter\Entity\Entity;

class HistoryEntity extends Entity
{
    /**
     * 流水帳主鍵
     *
     * @var int
     */
    protected $h_key;

    /**
     * 商品外來鍵
     *
     * @var int
     */
    protected $p_key;

    /**
     * 訂單外來鍵
     *
     * @var int
     */
    protected $o_key;

    /**
     * 本次訂單影響的數量
     *
     * @var int
     */
    protected $amount;

    /**
     * 類別
     *
     * @var string
     */
    protected $type;

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
        'h_key' => 'integer'
    ];

    protected $dates = []; 
}
