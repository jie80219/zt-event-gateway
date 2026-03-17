<?php

namespace AnserGateway\ServiceDiscovery\LoadBalance;

/**
 * 負載平衡演算法統一實作介面
 */
interface LoadBalanceInterface
{
    public function do(array $services): array;
}