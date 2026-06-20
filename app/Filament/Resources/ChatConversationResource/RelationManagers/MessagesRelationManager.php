<?php

namespace App\Filament\Resources\ChatConversationResource\RelationManagers;

use App\Models\ChatMessage;
use Filament\Infolists;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static ?string $title = 'Messages';

    protected static ?string $icon = 'heroicon-o-chat-bubble-bottom-center-text';

    /**
     * Read-only: no create/edit/delete in Phase 6A. The conversation policy's
     * update=false already forces read-only, this is belt-and-suspenders.
     */
    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sequence_number', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('sequence_number')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ChatMessage::ROLE_USER => 'info',
                        ChatMessage::ROLE_ASSISTANT => 'success',
                        ChatMessage::ROLE_SYSTEM => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ChatMessage::STATUS_COMPLETED => 'success',
                        ChatMessage::STATUS_PENDING => 'warning',
                        ChatMessage::STATUS_FAILED => 'danger',
                        default => 'gray',
                    }),

                // 100-character preview. Full content is available via the
                // "Full message" view action (modal) below.
                Tables\Columns\TextColumn::make('content')
                    ->label('Preview')
                    ->limit(100)
                    ->placeholder('— (no content)')
                    ->wrap(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Sent')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Full message')
                    ->modalHeading('Message content')
                    ->modalSubmitAction(false)
                    ->infolist([
                        Infolists\Components\TextEntry::make('role')
                            ->badge(),
                        Infolists\Components\TextEntry::make('status')
                            ->badge(),
                        Infolists\Components\TextEntry::make('content')
                            ->label('Full content')
                            ->placeholder('— (no content)')
                            ->columnSpanFull(),
                    ]),
            ])
            ->bulkActions([]);
    }
}
