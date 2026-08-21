<?php
namespace GlpiPlugin\Ticketeamer;

final class Queue
{
    public static function enqueue(int $ticketId, int $userId, string $email): int
    {
        global $DB;

        $now = date('Y-m-d H:i:s');
        $DB->insert(PLUGIN_TICKETEAMER_TABLE, [
            'tickets_id' => $ticketId,
            'users_id' => $userId,
            'recipient_email' => $email,
            'status' => 'pending',
            'attempts' => 0,
            'last_error' => null,
            'date_creation' => $now,
            'date_mod' => $now,
        ]);

        return (int) $DB->insertId();
    }

    public static function pending(int $limit): array
    {
        global $DB;

        $iterator = $DB->request([
            'FROM' => PLUGIN_TICKETEAMER_TABLE,
            'WHERE' => [
                'OR' => [
                    ['status' => 'pending'],
                    [
                        'status' => 'processing',
                        'date_mod' => ['<', date('Y-m-d H:i:s', strtotime('-10 minutes'))],
                    ],
                ],
            ],
            'ORDER' => 'id ASC',
            'LIMIT' => max(1, $limit),
        ]);

        return iterator_to_array($iterator, false);
    }

    public static function markProcessing(int $id): void
    {
        global $DB;
        $DB->update(PLUGIN_TICKETEAMER_TABLE, [
            'status' => 'processing',
            'date_mod' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public static function markSent(int $id): void
    {
        global $DB;
        $DB->update(PLUGIN_TICKETEAMER_TABLE, [
            'status' => 'sent',
            'sent_at' => date('Y-m-d H:i:s'),
            'date_mod' => date('Y-m-d H:i:s'),
            'last_error' => null,
        ], ['id' => $id]);
    }

    public static function markFailed(int $id, string $error, int $retryLimit): void
    {
        global $DB;

        $current = $DB->request([
            'FROM' => PLUGIN_TICKETEAMER_TABLE,
            'WHERE' => ['id' => $id],
            'LIMIT' => 1,
        ])->current();

        $attempts = ((int) ($current['attempts'] ?? 0)) + 1;
        $status = $attempts >= $retryLimit ? 'failed' : 'pending';

        $DB->update(PLUGIN_TICKETEAMER_TABLE, [
            'status' => $status,
            'attempts' => $attempts,
            'last_error' => mb_substr($error, 0, 4000),
            'date_mod' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }
}
