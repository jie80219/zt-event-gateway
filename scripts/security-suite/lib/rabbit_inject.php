<?php

declare(strict_types=1);

ini_set('display_errors', 'stderr');

/**
 * Direct AMQP injection — bypass gateway and publish forged envelopes
 * straight onto the RabbitMQ exchange. Verifies worker's fail-closed
 * behavior at the consumer layer.
 *
 * Usage:
 *   php rabbit_inject.php <case_id> [--queue=order_queue] [--exchange=events]
 *
 * The script publishes, then waits briefly and counts whether the message
 * was ack'd (consumed successfully) or rejected (nack/dead-lettered).
 * Detection is log-based since RabbitMQ basic_publish doesn't return ack.
 */

require_once __DIR__ . '/attack_client.php';

use PhpAmqpLib\Connection\AMQPSocketConnection;
use PhpAmqpLib\Message\AMQPMessage;

$caseId = $argv[1] ?? '';
if ($caseId === '') {
    fwrite(STDERR, "usage: rabbit_inject.php <case_id>\n");
    exit(2);
}

$options = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z_]+)=(.+)$/', $arg, $m)) {
        $options[$m[1]] = $m[2];
    }
}
$exchange = $options['exchange'] ?? (getenv('AMQP_EXCHANGE') ?: 'events');
$routingKey = $options['routing_key'] ?? (getenv('REQUEST_ROUTING_KEY') ?: 'request.new');
$queueName = $options['queue'] ?? (getenv('REQUEST_QUEUE') ?: 'order_queue');
$host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
$port = (int) (getenv('RABBITMQ_PORT') ?: 5672);
$user = getenv('RABBITMQ_USER') ?: 'zt';
$pass = getenv('RABBITMQ_PASS') ?: 'ztpass';

try {
    $built = buildCase($caseId);
} catch (Throwable $e) {
    fwrite(STDERR, "buildCase({$caseId}) failed: {$e->getMessage()}\n");
    exit(3);
}

$meta = caseMetadata()[$caseId];

$conn = new AMQPSocketConnection($host, $port, $user, $pass);
$chan = $conn->channel();
$chan->exchange_declare($exchange, 'direct', true, true, false);

$startUs = hrtime(true);

$body = json_encode($built['envelope'], JSON_UNESCAPED_SLASHES);
$msg = new AMQPMessage($body, [
    'content_type'  => 'application/json',
    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
    'message_id'    => 'atk-' . $caseId . '-' . bin2hex(random_bytes(4)),
    'type'          => 'gateway.request',
]);

// For Q02 — forged rollback — publish to a different routing key
$useKey = $routingKey;
if ($caseId === 'Q02') {
    $useKey = 'RollbackOrderEvent';
}

$chan->basic_publish($msg, $exchange, $useKey);

// Peek queue depth before/after to see whether worker consumed it.
sleep(1);

$depth = null;
try {
    [$qname, $msgCount, $_consumerCount] = $chan->queue_declare($queueName, true, true, false, false);
    $depth = $msgCount;
} catch (Throwable $e) {
    $depth = -1;
}

$chan->close();
$conn->close();

$elapsedUs = (int) ((hrtime(true) - $startUs) / 1000);

$out = [
    'case_id'        => $caseId,
    'category'       => $meta['category'],
    'description'    => $meta['desc'],
    'layer_expected' => $meta['layer'],
    'profile'        => getenv('PROFILE') ?: 'D-full-zt',
    'attempt' => [
        'sent_at'       => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
        'request_kind'  => 'amqp',
        'payload_sha256'=> hash('sha256', $body),
        'routing_key'   => $useKey,
        'exchange'      => $exchange,
    ],
    'outcome' => [
        // amqp_ack is a rough indicator: if queue depth stays > 0 a bit later,
        // the message wasn't consumed cleanly (likely nack'd back). If depth
        // is 0, worker ack'd it → either accepted OR UnrecoverableMessageException
        // (which also ack's to prevent redelivery). Stage3 shell cross-references
        // with docker logs to distinguish.
        'status'            => 'unknown',
        'is_expected'       => true, // shell will overwrite
        'http_code'         => null,
        'amqp_ack'          => ($depth === 0),
        'queue_depth_after' => $depth,
        'rejected_by'       => null, // shell will fill from logs
        'reject_reason'     => null,
        'exception'         => null,
        'detect_latency_us' => $elapsedUs,
    ],
    'log_snippet' => '(shell stage will fill)',
];

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
