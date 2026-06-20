<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChatConversationResource\Pages;
use App\Filament\Resources\ChatConversationResource\RelationManagers;
use App\Models\ChatAiRequest;
use App\Models\ChatConversation;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ChatConversationResource extends Resource
{
    protected static ?string $model = ChatConversation::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Chatbot';

    protected static ?string $navigationLabel = 'Conversations';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 1;

    /**
     * Conversations originate from the student API; they are never created
     * from the admin panel.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Admins (super_admin / support_admin) see every DB-backed student
     * conversation, including those the student has hidden. Eager-load the
     * student and pre-count children to avoid N+1 queries on the list.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['student', 'chatSummary'])
            ->withCount(['messages', 'aiRequests']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('student.full_name')
                    ->label('Student')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('student.student_id')
                    ->label('Student ID')
                    ->searchable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('Title')
                    ->searchable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ChatConversation::STATUS_ACTIVE => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('visibility')
                    ->label('Visibility')
                    ->badge()
                    ->state(fn (ChatConversation $record): string => $record->deleted_by_student_at === null
                        ? 'Active'
                        : 'Student-Hidden')
                    ->color(fn (string $state): string => $state === 'Active' ? 'success' : 'warning'),

                Tables\Columns\TextColumn::make('messages_count')
                    ->label('Messages')
                    ->sortable(),

                Tables\Columns\TextColumn::make('ai_requests_count')
                    ->label('AI Requests')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('last_message_at')
                    ->label('Last Message')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('chatSummary.summary_text')
                    ->label('Summary')
                    ->limit(100)
                    ->placeholder('Not summarized yet')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('summary_updated_at')
                    ->label('Summary Updated')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        ChatConversation::STATUS_ACTIVE => 'Active',
                    ]),

                Tables\Filters\TernaryFilter::make('visibility')
                    ->label('Student visibility')
                    ->placeholder('All conversations')
                    ->trueLabel('Active only')
                    ->falseLabel('Student-hidden only')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('deleted_by_student_at'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('deleted_by_student_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                static::makeRestoreTableAction(),
                static::makeHardDeleteTableAction(),
            ])
            // No bulk actions — single hard-delete only, no bulk hard delete.
            ->bulkActions([]);
    }

    // ──────────────────────────────────────────────
    // Phase 6B — restore + single hard-delete actions
    // ──────────────────────────────────────────────

    /**
     * True when the conversation has any AI request still queued or processing.
     * Hard-delete must be blocked while in-flight AI work exists.
     */
    public static function hasInFlightAiWork(ChatConversation $record): bool
    {
        return $record->aiRequests()
            ->whereIn('status', [
                ChatAiRequest::STATUS_QUEUED,
                ChatAiRequest::STATUS_PROCESSING,
            ])
            ->exists();
    }

    /**
     * Restore a student-hidden conversation. Idempotent: a conversation that is
     * already visible is left untouched with a warning notification.
     */
    public static function restoreConversation(ChatConversation $record): void
    {
        if ($record->deleted_by_student_at === null) {
            Notification::make()
                ->title('Conversation already visible')
                ->body('This conversation is not hidden by the student.')
                ->warning()
                ->send();

            return;
        }

        $record->forceFill(['deleted_by_student_at' => null])->save();

        Notification::make()
            ->title('Conversation restored')
            ->body('The student can see this conversation again.')
            ->success()
            ->send();
    }

    /**
     * Permanently delete a conversation if no AI work is queued/processing.
     * The DB cascade removes its messages and AI requests.
     *
     * @return bool true when the conversation was deleted, false when blocked.
     */
    public static function hardDeleteConversation(ChatConversation $record): bool
    {
        if (static::hasInFlightAiWork($record)) {
            Notification::make()
                ->title('Cannot delete conversation')
                ->body('This conversation has queued or processing AI work. Wait until it finishes, then try again.')
                ->danger()
                ->send();

            return false;
        }

        // DB foreign keys cascade-delete chat_messages and chat_ai_requests.
        $record->delete();

        Notification::make()
            ->title('Conversation permanently deleted')
            ->body('The conversation and all of its messages and AI requests were permanently deleted.')
            ->success()
            ->send();

        return true;
    }

    public static function makeRestoreTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('restore')
            ->label('Restore')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            // Visible only for hidden conversations and only to permitted admins.
            ->visible(fn (ChatConversation $record): bool => $record->deleted_by_student_at !== null
                && (auth()->user()?->can('restore', $record) ?? false))
            ->requiresConfirmation()
            ->modalHeading('Restore conversation')
            ->modalDescription('Make this student-hidden conversation visible to the student again.')
            ->modalSubmitActionLabel('Restore')
            ->action(fn (ChatConversation $record) => static::restoreConversation($record));
    }

    public static function makeHardDeleteTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('hardDelete')
            ->label('Hard delete')
            ->icon('heroicon-o-trash')
            ->color('danger')
            // Shown to permitted admins regardless of in-flight AI work; the
            // queued/processing block is enforced on click (race-safe).
            ->visible(fn (ChatConversation $record): bool => auth()->user()?->can('delete', $record) ?? false)
            ->requiresConfirmation()
            ->modalHeading('Permanently delete conversation')
            ->modalDescription('This permanently deletes the conversation and cascades to ALL of its messages and AI requests. This action cannot be undone.')
            ->modalSubmitActionLabel('Delete permanently')
            ->action(fn (ChatConversation $record) => static::hardDeleteConversation($record));
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\MessagesRelationManager::class,
            RelationManagers\AiRequestsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChatConversations::route('/'),
            'view' => Pages\ViewChatConversation::route('/{record}'),
        ];
    }
}
