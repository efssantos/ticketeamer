<?php
namespace GlpiPlugin\Ticketeamer;

final class QueueTask extends \CommonDBTM
{
    public static function cronInfo($name): array
    {
        if ($name === 'Process') {
            return [
                'description' => __('Send queued Microsoft Teams private-chat notifications', 'ticketeamer'),
                'parameter' => __('Maximum notifications per run', 'ticketeamer'),
            ];
        }

        return [];
    }

    public static function cronProcess($task = null): int
    {
        if (!(bool) Config::get('enabled', 1)) {
            return 0;
        }

        $limit = $task !== null ? (int) ($task->fields['param'] ?? 25) : 25;
        $retryLimit = max(1, (int) Config::get('retry_limit', 5));
        $graph = new GraphClient(Config::all());
        $processed = 0;

        foreach (Queue::pending($limit) as $row) {
            $queueId = (int) $row['id'];
            Queue::markProcessing($queueId);

            try {
                $ticket = new \Ticket();
                if (!$ticket->getFromDB((int) $row['tickets_id'])) {
                    throw new \RuntimeException('Ticket no longer exists.');
                }

                $message = MessageBuilder::ticketHtml($ticket);
                $graph->sendPrivateMessage((string) $row['recipient_email'], $message);
                Queue::markSent($queueId);
                $processed++;
            } catch (\Throwable $e) {
                Queue::markFailed($queueId, $e->getMessage(), $retryLimit);
                \Toolbox::logInFile('ticketeamer', sprintf("Queue #%d failed: %s\n", $queueId, $e->getMessage()));
            }
        }

        return $processed;
    }
}
