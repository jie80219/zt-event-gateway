<?php
namespace AnserGateway\ServiceDiscovery\LoadBalance;

use AnserGateway\Util\RedisClient;

class EntropyScoring
{
    private array $metricKeys = ['cpu', 'mem', 'latency', 'load', 'disk_io_total', 'network_io_total', 'cpu_cores', 'mem_total'];
    private array $positiveKeys = ['cpu_cores', 'mem_total'];

    public function recalculateScores(): void
    {
        $redis = RedisClient::get();
        $metricsPattern = $this->env('LOAD_BALANCE_METRICS_PATTERN', 'metrics:*');
        $scoreKey = $this->env('LOAD_BALANCE_SCORE_KEY', 'metrics:anser-gateway');

        $hosts = [];
        foreach ($redis->keys($metricsPattern) as $key) {
            if ($key === $scoreKey) {
                continue;
            }
            $ttl = $redis->ttl($key);
            if ($ttl === -2) {
                continue;
            }
            if (str_starts_with($key, 'metrics:')) {
                $hosts[] = str_replace('metrics:', '', $key);
            }
        }

        if (empty($hosts)) {
            echo "[EntropyScoring] No valid metrics found.\n";
            return;
        }

        $services = [];
        foreach ($hosts as $host) {
            $metrics = $redis->hGetAll("metrics:$host");

            if (
                isset($metrics['cpu'], $metrics['mem'], $metrics['latency'], $metrics['load'],
                      $metrics['disk_io'], $metrics['network_io'], $metrics['cpu_cores'], $metrics['mem_total'])
            ) {
                $diskIO = json_decode($metrics['disk_io'], true);
                $netIO = json_decode($metrics['network_io'], true);
                if (!is_array($diskIO) || !is_array($netIO)) continue;

                $services[] = [
                    'host' => $host,
                    'metrics' => [
                        'cpu' => (float)$metrics['cpu'],
                        'mem' => (float)$metrics['mem'],
                        'latency' => (float)$metrics['latency'],
                        'load' => (float)$metrics['load'],
                        'disk_io_total' => ($diskIO['read_kb'] ?? 0) + ($diskIO['write_kb'] ?? 0),
                        'network_io_total' => ($netIO['bytes_in_kbps'] ?? 0) + ($netIO['bytes_out_kbps'] ?? 0),
                        'cpu_cores' => (float)$metrics['cpu_cores'],
                        'mem_total' => (float)$metrics['mem_total'],
                    ]
                ];
            }
        }

        if (empty($services)) {
            echo "[EntropyScoring] No valid service metrics after parsing.\n";
            return;
        }

        if (count($services) === 1) {
            $host = $services[0]['host'];
            $singleScore = [$host => 1.0];
            $redis->del($scoreKey);
            $redis->hMSet($scoreKey, $singleScore);
            CachedScoreManager::getInstance()->updateCacheDirectly($singleScore);
            echo "[EntropyScoring] Single host mode, score set to 1.0 for {$host}\n";
            return;
        }

        $matrix = [];
        foreach ($this->metricKeys as $j => $key) {
            $col = array_column(array_column($services, 'metrics'), $key);
            $min = min($col);
            $max = max($col);

            foreach ($col as $i => $val) {
                if (in_array($key, $this->positiveKeys)) {
                    $matrix[$i][$j] = ($max - $min > 0) ? (($val - $min) / ($max - $min)) : 0;
                } else {
                    $matrix[$i][$j] = ($max - $min > 0) ? 1 - (($val - $min) / ($max - $min)) : 0;
                }
            }
        }

        $m = count($matrix);
        $n = count($this->metricKeys);
        $k = 1 / log($m);
        $entropy = [];

        for ($j = 0; $j < $n; $j++) {
            $sum = 0;
            for ($i = 0; $i < $m; $i++) {
                $p = $matrix[$i][$j];
                if ($p > 0) $sum += $p * log($p);
            }
            $entropy[$j] = -$k * $sum;
        }

        $diff = array_map(fn($e) => 1 - $e, $entropy);
        $weightSum = array_sum($diff);
        $weights = array_map(fn($d) => $d / $weightSum, $diff);

        echo "[EntropyScoring] Weights: " . json_encode($weights) . "\n";

        $scoreMap = [];
        foreach ($matrix as $i => $row) {
            $score = array_sum(array_map(fn($v, $w) => $v * $w, $row, $weights));
            $host = $services[$i]['host'];
            $scoreMap[$host] = round($score, 6); // 建議四捨五入防止浮點誤差
        }

        // 寫入 Redis
        $redis->multi();
        $redis->del($scoreKey);
        $redis->hMSet($scoreKey, $scoreMap);
        $redis->exec();

        echo "[EntropyScoring] Scores saved to Redis.\n";

        // 更新本地快取
        CachedScoreManager::getInstance()->updateCacheDirectly($scoreMap);
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
