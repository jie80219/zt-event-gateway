<?php
namespace AnserGateway\ServiceDiscovery\LoadBalance;

use AnserGateway\Util\RedisClient;
use Workerman\Timer;

class CachedScoreManager
{
    private static ?CachedScoreManager $instance = null;
    private array $scoreCache = [];

    private function __construct()
    {
        $this->startDebugMonitor();   // 啟動時自動開始監控與同步快取
    }

    public static function getInstance(): CachedScoreManager
    {
        if (self::$instance === null) {
            self::$instance = new CachedScoreManager();
        }
        return self::$instance;
    }

    public function getScore(string $host): ?float
    {
        return $this->scoreCache[$host] ?? null;
    }

    public function getAllScores(): array
    {
        return $this->scoreCache;
    }

    public function updateCacheDirectly(array $scores): void
    {
        $this->scoreCache = $scores;
        error_log("[CachedScoreManager] Cache updated directly: " . json_encode($scores));
    }

    private function startDebugMonitor(): void
    {
        $syncInterval = (int) $this->env('LOAD_BALANCE_CACHE_SYNC_INTERVAL', '5');
        if ($syncInterval <= 0) {
            $syncInterval = 5;
        }

        Timer::add($syncInterval, function () {
            try {
                $scoreKey = $this->env('LOAD_BALANCE_SCORE_KEY', 'metrics:anser-gateway');
                $scores = RedisClient::get()->hGetAll($scoreKey);

                if (!empty($scores)) {
                    foreach ($scores as $host => $score) {
                        $this->scoreCache[$host] = (float)$score;
                    }
                    error_log("[CachedScoreManager] [" . date('H:i:s') . "] Cache snapshot: " . json_encode($this->scoreCache));
                } else {
                    error_log("[CachedScoreManager] [" . date('H:i:s') . "] Cache snapshot: EMPTY");
                }
            } catch (\Exception $e) {
                error_log("[CachedScoreManager] Redis error during monitor: " . $e->getMessage());
            }
        });
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
