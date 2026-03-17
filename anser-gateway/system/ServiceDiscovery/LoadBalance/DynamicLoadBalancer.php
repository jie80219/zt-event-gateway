<?php
namespace AnserGateway\ServiceDiscovery\LoadBalance;

use AnserGateway\ServiceDiscovery\LoadBalance\LoadBalanceInterface;
use AnserGateway\ServiceDiscovery\LoadBalance\CachedScoreManager;

class DynamicLoadBalancer implements LoadBalanceInterface
{
    /**
     * 根據熵值權重選擇服務
     *
     * @param array $services [['address' => '10.1.1.x', 'name' => 'service-a', ...], ...]
     * @return array
     */
    public function do(array $services): array
    {
        if (empty($services)) {
            throw new \InvalidArgumentException('服務列表為空');
        }

        $scores = [];
        $scoreManager = CachedScoreManager::getInstance();
        $aliasMap = $this->parseAliasMap(
            $this->env('LOAD_BALANCE_SCORE_ALIAS', '')
        );

        foreach ($services as $service) {
            $candidates = [];
            if (isset($service['address'])) {
                $candidates[] = (string) $service['address'];
                if (isset($aliasMap[$service['address']])) {
                    $candidates[] = $aliasMap[$service['address']];
                }
            }
            if (isset($service['name'])) {
                $candidates[] = (string) $service['name'];
            }

            $score = null;
            foreach ($candidates as $candidate) {
                $score = $scoreManager->getScore($candidate);
                if ($score !== null) {
                    break;
                }
            }

            if ($score !== null && $score > 0) {
                $scores[] = [
                    'service' => $service,
                    'score' => $score,
                ];
            }
        }

        if (empty($scores)) {
            error_log("[DynamicLoadBalancer] No usable scores, fallback to random");
            return $services[array_rand($services)];
        }

        $total = array_sum(array_column($scores, 'score'));
        if ($total <= 0) {
            error_log("[DynamicLoadBalancer] Invalid score sum, fallback to random");
            return $services[array_rand($services)];
        }

        $rand = mt_rand() / mt_getrandmax();
        $acc = 0;

        foreach ($scores as $entry) {
            $acc += $entry['score'] / $total;
            if ($rand <= $acc) {
                return $entry['service'];
            }
        }

        return $services[array_rand($services)];
    }

    private function parseAliasMap(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $result = [];
        foreach (explode(',', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $pair, 2));
            if ($key !== '' && $value !== '') {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
