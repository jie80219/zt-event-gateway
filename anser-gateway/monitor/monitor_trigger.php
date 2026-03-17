<?php
// monitor_trigger.php
require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPSocketConnection;
use PhpAmqpLib\Message\AMQPMessage;
use AnserGateway\Util\RedisClient;

bootstrapEnv();

$mqHost = envValue('LOAD_BALANCE_AMQP_HOST', envValue('AMQP_HOST', 'rabbitmq'));
$mqPort = (int) envValue('LOAD_BALANCE_AMQP_PORT', envValue('AMQP_PORT', '5672'));
$mqUser = envValue('LOAD_BALANCE_AMQP_USER', envValue('AMQP_USER', 'guest'));
$mqPass = envValue('LOAD_BALANCE_AMQP_PASSWORD', envValue('AMQP_PASSWORD', 'guest'));
$mqQueue = envValue('LOAD_BALANCE_RECALC_QUEUE', 'recalc_weight');
$metricsPattern = envValue('LOAD_BALANCE_METRICS_PATTERN', 'metrics:*');
$monitorInterval = (int) envValue('LOAD_BALANCE_MONITOR_INTERVAL', '5');
$stdThreshold = (float) envValue('LOAD_BALANCE_STD_THRESHOLD', '0.8');

if ($monitorInterval <= 0) {
    $monitorInterval = 5;
}

$metricKeys = ['cpu', 'mem', 'latency', 'load', 'disk_io_total', 'network_io_total'];

while (true) {
    echo "[monitor] Checking cluster load...\n";

    $redis = RedisClient::get();
    $services = [];
    foreach ($redis->keys($metricsPattern) as $key) {
        if ($key === envValue('LOAD_BALANCE_SCORE_KEY', 'metrics:anser-gateway')) {
            continue;
        }
        $host = str_replace('metrics:', '', $key);
        $metrics = $redis->hGetAll($key);

        if (isset($metrics['cpu'], $metrics['mem'], $metrics['latency'], $metrics['load'], $metrics['disk_io'], $metrics['network_io'])) {
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
                    'network_io_total' => ($netIO['bytes_in'] ?? 0) + ($netIO['bytes_out'] ?? 0),
                ]
            ];
        }
    }

    if (!empty($services)) {
        $matrix = [];
        foreach ($metricKeys as $j => $key) {
            $col = array_column(array_column($services, 'metrics'), $key);
            $min = min($col);
            $max = max($col);
            foreach ($col as $i => $val) {
                // 反轉型標準化：值越大越差 → 越小越好
                $matrix[$i][$j] = ($max - $min > 0) ? 1 - (($val - $min) / ($max - $min)) : 0;
            }
        }

        $sumList = array_map(fn($row) => array_sum($row), $matrix);
        $avg = array_sum($sumList) / count($sumList);
        $std = sqrt(array_sum(array_map(fn($s) => pow($s - $avg, 2), $sumList)) / count($sumList));

        echo "[monitor] Normalized vector std = {$std}\n";

        if ($std > $stdThreshold) {
            echo "[monitor] Triggering recalculation...\n";

            try {
                $connection = new AMQPSocketConnection($mqHost, $mqPort, $mqUser, $mqPass);
                $channel = $connection->channel();
                $channel->queue_declare($mqQueue, false, true, false, false);

                $msg = new AMQPMessage('recalculate');
                $channel->basic_publish($msg, '', $mqQueue);
                echo "[monitor] MQ task sent.\n";

                $channel->close();
                $connection->close();
            } catch (Exception $e) {
                echo "[monitor] MQ failed: " . $e->getMessage() . "\n";
            }
        }
    }

    sleep($monitorInterval);
}

function envValue(string $key, string $default): string
{
    $value = getenv($key);
    return is_string($value) && $value !== '' ? $value : $default;
}

function bootstrapEnv(): void
{
    $root = dirname(__DIR__);
    $envPath = $root . '/env';
    if (!is_file($envPath)) {
        return;
    }

    try {
        (new \AnserGateway\Config\DotEnv($root, 'env'))->load();
    } catch (\Throwable $e) {
        fwrite(STDERR, "[monitor] failed to load env: {$e->getMessage()}\n");
    }
}
