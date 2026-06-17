<?php

namespace App\Filament\Resources\ChatConversationResource\Pages;

use App\Filament\Resources\ChatConversationResource;
use Filament\Resources\Pages\ListRecords;

class ListChatConversations extends ListRecords
{
    protected static string $resource = ChatConversationResource::class;

    /**
     * No header actions in Phase 6A — conversations cannot be created from
     * the admin panel.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
