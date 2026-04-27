<?php

namespace App\Entities\v1;

use CodeIgniter\Entity\Entity;

class ProductionEntity extends Entity
{
    /**
     * 主鍵
     *
     * @var int
     */
    protected $p_key;

    /**
     * 產品名稱
     *
     * @var string
     */
    protected $name;

    /**
     * 產品描述
     *
     * @var string
     */
    protected $description;

    /**
     * 文字價格
     *
     * @var string
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
        'p_key' => 'integer'
    ];

    protected $dates = []; 
}
