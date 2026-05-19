<?php

namespace App\Services;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitMqClient
{
    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $channel = null;

    public function channel(): AMQPChannel
    {
        if ($this->channel) {
            return $this->channel;
        }

        $this->connection = new AMQPStreamConnection(
            host: (string) env('RABBITMQ_HOST', 'rabbitmq'),
            port: (int) env('RABBITMQ_PORT', 5672),
            user: (string) env('RABBITMQ_USER', 'guest'),
            password: (string) env('RABBITMQ_PASSWORD', 'guest'),
            vhost: (string) env('RABBITMQ_VHOST', '/'),
        );

        $this->channel = $this->connection->channel();
        $this->declareTopology();

        return $this->channel;
    }

    public function publish(string $payload, int $priority): void
    {
        $message = new AMQPMessage(
            $payload,
            [
                'content_type' => 'application/json',
                'delivery_mode' => 2,
                'priority' => $priority,
            ]
        );

        $this->channel()->basic_publish(
            $message,
            config('notifications.exchange'),
            config('notifications.routing_key'),
        );
    }

    public function retry(string $payload, int $priority, int $attempt): void
    {
        $delay = config("notifications.retry_delays_ms.$attempt", 30000);

        $message = new AMQPMessage(
            $payload,
            [
                'content_type' => 'application/json',
                'delivery_mode' => 2,
                'priority' => $priority,
                'expiration' => (string) $delay,
            ]
        );

        $this->channel()->basic_publish(
            $message,
            config('notifications.exchange'),
            config('notifications.retry_routing_key'),
        );
    }

    public function close(): void
    {
        try {
            $this->channel?->close();
        } catch (\Throwable) {
            //
        }

        try {
            $this->connection?->close();
        } catch (\Throwable) {
            //
        }

        $this->channel = null;
        $this->connection = null;
    }

    private function declareTopology(): void
    {
        $channel = $this->channel;
        $exchange = config('notifications.exchange');
        $queue = config('notifications.queue');
        $retryQueue = config('notifications.retry_queue');
        $routingKey = config('notifications.routing_key');
        $retryRoutingKey = config('notifications.retry_routing_key');

        $channel->exchange_declare($exchange, 'direct', false, true, false);

        $channel->queue_declare(
            $queue,
            false,
            true,
            false,
            false,
            false,
            [
                'x-max-priority' => ['I', config('notifications.max_priority')],
            ]
        );

        $channel->queue_bind($queue, $exchange, $routingKey);

        $channel->queue_declare(
            $retryQueue,
            false,
            true,
            false,
            false,
            false,
            [
                'x-dead-letter-exchange' => ['S', $exchange],
                'x-dead-letter-routing-key' => ['S', $routingKey],
                'x-max-priority' => ['I', config('notifications.max_priority')],
            ]
        );

        $channel->queue_bind($retryQueue, $exchange, $retryRoutingKey);
    }
}
