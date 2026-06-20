<?php

namespace App\Filament\Resources\ChatConversationResource\RelationManagers;

use App\Models\ChatAiRequest;
use Filament\Infolists;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class AiRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'aiRequests';

    protected static ?string $title = 'AI Requests';

    protected static ?string $icon = 'heroicon-o-cpu-chip';

    /**
     * Read-only: no create/edit/delete in Phase 6A.
     */
    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ChatAiRequest::STATUS_QUEUED => 'gray',
                        ChatAiRequest::STATUS_PROCESSING => 'warning',
                        ChatAiRequest::STATUS_COMPLETED => 'success',
                        ChatAiRequest::STATUS_FAILED => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('attempt_number')
                    ->label('Attempt')
                    ->sortable(),

                Tables\Columns\TextColumn::make('error_code')
                    ->label('Error Code')
                    ->badge()
                    ->color('danger')
                    ->placeholder('—'),

                // Safe preview of the stored (already-safe local) error message.
                Tables\Columns\TextColumn::make('error_message')
                    ->label('Error Message')
                    ->limit(100)
                    ->placeholder('—')
                    ->wrap(),

                Tables\Columns\TextColumn::make('submitted_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('completed_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('failed_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Details')
                    ->modalHeading('AI request details')
                    ->modalSubmitAction(false)
                    ->infolist([
                        Infolists\Components\TextEntry::make('uuid')
                            ->label('Request ID'),
                        Infolists\Components\TextEntry::make('status')
                            ->badge(),
                        Infolists\Components\TextEntry::make('attempt_number')
                            ->label('Attempt'),
                        Infolists\Components\TextEntry::make('error_code')
                            ->label('Error code')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('error_message')
                            ->label('Error message')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('submitted_at')
                            ->dateTime()
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('completed_at')
                            ->dateTime()
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('failed_at')
                            ->dateTime()
                            ->placeholder('—'),
                    ]),
            ])
            ->bulkActions([]);
    }
}
