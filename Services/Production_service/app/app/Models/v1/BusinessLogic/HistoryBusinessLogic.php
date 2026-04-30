<?php

namespace App\Models\v1\BusinessLogic;

use CodeIgniter\API\ResponseTrait;

use App\Models\v1\HistoryModel;
use App\Entities\v1\HistoryEntity;

class HistoryBusinessLogic
{
    use ResponseTrait;

    /**
     * 新增流水帳
     *
     * @param integer $p_key
     * @param string  $o_key
     * @param integer $amount
     * @param string  $type
     * @return integer | null 新增是否成功 成功回傳新增 key
     */
    static function createHistory(int $p_key, string $o_key, int $amount, string $type): ?int
    {
        $historyModel  = new HistoryModel;
        $historyEntity = new HistoryEntity;

        $historyEntity->p_key  = $p_key;
        $historyEntity->o_key  = $o_key;
        $historyEntity->amount = $amount;
        $historyEntity->type   = $type;

        $historyModel->insert($historyEntity);

        $insertID = $historyModel->getInsertID();

        return $insertID;
    }

    /**
     * 判斷流水帳是否重複
     *
     * @param integer $p_key
     * @param string  $o_key
     * @param string  $type
     * @return HistoryEntity | null
     */
    static function verfiyType(int $p_key, string $o_key, string $type): ?HistoryEntity
    {
        $historyModel = new HistoryModel();

        $historyEntity = $historyModel->where('o_key', $o_key)
                                      ->where('p_key', $p_key)
                                      ->where('type', $type)
                                      ->first();

        return $historyEntity;
    }

    /**
     * 刪除庫存記錄
     *
     * @param integer $h_key
     * @return void
     */
    static function delete(int $h_key)
    {
        $historyModel = new HistoryModel;

        $historyModel->delete($h_key);
    }

    /**
     * 驗證商品是否有新增退貨或補償
     *
     * @param string $o_key
     * @return HistoryEntity | null
     */
    static function verfiyCreated(string $o_key): ?HistoryEntity
    {
        $historyModel = new HistoryModel();
        $historyEntity = $historyModel->where('o_key', $o_key)
                                      ->whereNotIn('type', ["reduce", "compensate"])
                                      ->where('type', "create")
                                      ->first();
        return $historyEntity;
    }
}
