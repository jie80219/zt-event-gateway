<?php
namespace Services;

use SDPMlab\Anser\Service\SimpleService;
use SDPMlab\Anser\Service\ActionInterface;
use Filters\FailHandlerFilter;
use Filters\JsonDoneHandlerFilter;
use Services\Models\OrderProductDetail;

class OrderService extends SimpleService
{
    protected $serviceName = "OrderService";
    protected $filters = [
        "before" => [
            JsonDoneHandlerFilter::class,
            FailHandlerFilter::class
        ],
        "after" => [],
    ];
    protected $retry = 1;
    protected $retryDelay = 0.2;
    protected $timeout = 2.0;
    protected $options = [];

    /**
     * 取得使用者訂單清單
     *
     * @param integer $userId
     * @param integer $limit
     * @param integer $offest
     * @param string $orderBy DESC or ASC
     * @param string $search
     * @return ActionInterface
     */
    public function orderListAction(
        string $userId,
        int $limit = 10,
        int $offest = 0,
        string $orderBy = "DESC",
        string $search = ""
    ): ActionInterface {
        return $this->getAction(
            method: "GET",
            path: "/api/v1/order"
        )->setOptions([
            "headers" => [
                "X-User-Key" => $userId
            ],
            "query" => [
                "limit" => $limit,
                "offset" => $offest,
                "isDesc" => $orderBy,
                "query" => $search
            ]
        ]);
    }

    /**
     * 取得使用者訂單資訊
     *
     * @param integer $userId
     * @param string $orderId
     * @return ActionInterface
     */
    public function orderInfoAction(string $userId, string $orderId): ActionInterface
    {
        return $this->getAction(
            method: "GET",
            path: "/api/v1/order/{$orderId}"
        )->setOptions([
            "headers" => [
                "X-User-Key" => $userId
            ]
        ]);
    }

    /**
     * 新增訂單
     *
     * @param integer $userId 使用者ID
     * @param string $orderId 訂單ID 
     * @param OrderProductDetail[] $productDetailList 購買的商品清單
     * @return ActionInterface
     */
    public function createOrderAction(
        string $userId,
        string $orderId,
        array $productDetailList
    ): ActionInterface {
        $productDetailList = array_map(function (OrderProductDetail $productDetail) {
            return $productDetail->toArray();
        }, $productDetailList);

        return $this->getAction(
            method: "POST",
            path: "/api/v1/order"
        )->setOptions([
            "headers" => [
                "X-User-Key" => $userId,
            ],
            "json" => [
                "o_key" => $orderId,
                "product_detail" => $productDetailList
            ]
        ]);
    }

    /**
     * 確認訂單完成（更新訂單狀態）
     *
     * @param integer $userId
     * @param string $orderId
     * @return ActionInterface
     */
    public function confirmOrderAction(string $userId, string $orderId): ActionInterface
    {
        return $this->getAction(
            method: "PUT",
            path: "/api/v1/order/{$orderId}"
        )->setOptions([
            "headers" => [
                "X-User-Key" => $userId
            ],
            "json" => [
                "status" => "completed"
            ]
        ]);
    }

    /**
     * 訂單補償（刪除）
     *
     * @param integer $userId
     * @param string $orderId
     * @return ActionInterface
     */
    public function compensateOrderAction(string $userId, string $orderId): ActionInterface
    {
        return $this->getAction(
            method: "DELETE",
            path: "/api/v1/order/{$orderId}"
        )->setOptions([
            "headers" => [
                "X-User-Key" => $userId
            ]
        ]);
    }

}
