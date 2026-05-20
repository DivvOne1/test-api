<?php

namespace App\Console\Commands;

use App\Services\NotificationProcessingService;
use App\Services\RabbitMqClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PhpAmqpLib\Message\AMQPMessage;
use JsonException;
use Throwable;
use UnexpectedValueException;

class ConsumeNotificationsCommand extends Command
{
    protected $signature = 'notifications:consume {--once : Process only one message}';

    protected $description = 'Consume notifications from RabbitMQ and deliver them via provider stubs.';

    public function __construct(
        private readonly RabbitMqClient $rabbitMqClient,
        private readonly NotificationProcessingService $processingService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $channel = $this->rabbitMqClient->channel();
        $queue = config('notifications.queue');
        $processed = 0;

        if ($this->option('once')) {
            $message = $channel->basic_get($queue, false);

            if (! $message) {
                $this->info('No messages available.');
                $this->rabbitMqClient->close();

                return self::SUCCESS;
            }

            $this->processMessage($message, $processed);
            $this->info("Processed {$processed} message(s).");
            $this->rabbitMqClient->close();

            return self::SUCCESS;
        }

        $callback = function (AMQPMessage $message) use (&$processed) {
            $this->processMessage($message, $processed);
        };

        $channel->basic_qos(0, 1, false);
        $channel->basic_consume($queue, '', false, false, false, false, $callback);

        while ($channel->is_consuming()) {
            $channel->wait();
        }

        $this->info("Processed {$processed} message(s).");
        $this->rabbitMqClient->close();

        return self::SUCCESS;
    }

    private function processMessage(AMQPMessage $message, int &$processed): void
    {
        try {
            $payload = $this->decodePayload($message);
            $this->processingService->process($payload['notification_id']);
            $message->ack();
            $processed++;
        } catch (JsonException|ModelNotFoundException|UnexpectedValueException $exception) {
            report($exception);
            $message->ack();
            $processed++;
        } catch (Throwable $exception) {
            report($exception);
            // Reject unexpected worker failures without requeue to avoid poison-message loops.
            $message->nack(false, false);
            $processed++;
        }
    }

    /**
     * @return array{notification_id: string}
     */
    private function decodePayload(AMQPMessage $message): array
    {
        $payload = json_decode($message->getBody(), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload) || ! array_key_exists('notification_id', $payload)) {
            throw new UnexpectedValueException('Notification payload must contain notification_id.');
        }

        if (! is_scalar($payload['notification_id']) || $payload['notification_id'] === '') {
            throw new UnexpectedValueException('Notification payload contains invalid notification_id.');
        }

        return [
            'notification_id' => (string) $payload['notification_id'],
        ];
    }
}
