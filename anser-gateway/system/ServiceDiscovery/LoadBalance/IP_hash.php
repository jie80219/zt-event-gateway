<?php
namespace AnserGateway\ServiceDiscovery\LoadBalance;

use AnserGateway\ServiceDiscovery\LoadBalance\LoadBalanceInterface;

class IP_hash implements LoadBalanceInterface
{
    /**
     * 根據客戶 IP 雜湊選擇服務
     *
     * @param array $services
     * @param string|null $clientIp
     * @return array
     */
    public function do(array $services, ?string $clientIp = null): array
    {
        if (empty($services)) {
            throw new \Exception("服務列表為空");
        }

        if (is_null($clientIp)) {
            $clientIp = $this->getClientIp();
        }

        // 若仍無法取得，產生模擬 IP
        if (is_null($clientIp)) {
            $clientIp = $this->generateFakeIp();
            error_log("[IP_hash] 無法取得實際 IP，已使用模擬 IP：{$clientIp}");
        }

        $hash = crc32($clientIp) & 0xffffffff;
        $index = $hash % count($services);

        return $services[$index];
    }

    /**
     * 嘗試從 header 中取得 client IP
     *
     * @return string|null
     */
    private function getClientIp(): ?string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }

        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }

        return null;
    }

    /**
     * 產生一個隨機模擬 IP（for 測試用）
     *
     * @return string
     */
    private function generateFakeIp(): string
    {
        return "192.168." . rand(0, 255) . "." . rand(1, 254);
    }
}
