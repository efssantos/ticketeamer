<?php
namespace GlpiPlugin\Ticketeamer\Hook;

use GlpiPlugin\Ticketeamer\Config;
use GlpiPlugin\Ticketeamer\Queue;

final class TicketHook
{
    public static function onTicketAdded(\Ticket $ticket): void
    {
        if (!(bool) Config::get('enabled', 1)) {
            return;
        }

        $categoryId = (int) ($ticket->fields['itilcategories_id'] ?? 0);
        if ($categoryId <= 0) {
            return;
        }

        $category = new \ITILCategory();
        if (!$category->getFromDB($categoryId)) {
            return;
        }

        $groupId = (int) ($category->fields['groups_id'] ?? 0);
        if ($groupId <= 0) {
            return;
        }

        $users = \Group_User::getGroupUsers($groupId, [
            'glpi_users.is_active' => 1,
            'glpi_users.is_deleted' => 0,
        ]);

        $queued = [];
        foreach ($users as $user) {
            $userId = (int) ($user['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $email = \UserEmail::getDefaultForUser($userId);
            if (!is_string($email) || trim($email) === '') {
                continue;
            }

            $queued[$userId . ':' . strtolower($email)] = [$userId, trim($email)];
        }

        foreach ($queued as [$userId, $email]) {
            Queue::enqueue((int) $ticket->getID(), $userId, $email);
        }
    }
}
