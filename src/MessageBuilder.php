<?php
namespace GlpiPlugin\Ticketeamer;

final class MessageBuilder
{
    public static function ticketHtml(\Ticket $ticket): string
    {
        $ticketId = (int) $ticket->getID();
        $title = htmlspecialchars((string) ($ticket->fields['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $status = htmlspecialchars((string) ($ticket->fields['status'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $priority = htmlspecialchars((string) ($ticket->fields['priority'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $category = '';

        $categoryId = (int) ($ticket->fields['itilcategories_id'] ?? 0);
        if ($categoryId > 0) {
            $categoryObject = new \ITILCategory();
            if ($categoryObject->getFromDB($categoryId)) {
                $category = htmlspecialchars((string) ($categoryObject->fields['completename'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        $url = \Toolbox::getItemTypeFormURL('Ticket') . '?id=' . $ticketId;
        $link = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $prefix = htmlspecialchars((string) Config::get('message_prefix', 'Novo chamado GLPI'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return sprintf(
            '<div><strong>%s</strong></div><div style="margin-top:8px"><strong>Chamado:</strong> <a href="%s">#%d</a></div><div><strong>Título:</strong> %s</div><div><strong>Categoria:</strong> %s</div><div><strong>Status:</strong> %s</div><div><strong>Prioridade:</strong> %s</div><div style="margin-top:10px"><a href="%s">Abrir chamado no GLPI</a></div>',
            $prefix,
            $link,
            $ticketId,
            $title,
            $category,
            $status,
            $priority,
            $link,
        );
    }
}
