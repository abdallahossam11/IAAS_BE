<?php

namespace App\Filament\Resources\ChatConversationResource\Pages;

use App\Filament\Resources\ChatConversationResource;
use App\Models\ChatConversation;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewChatConversation extends ViewRecord
{
    protected static string $resource = ChatConversationResource::class;

    /**
     * Restore + single hard-delete header actions (Phase 6B). They reuse the
     * exact same gating and behaviour as the list-table actions.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('restore')
                ->label('Restore')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn (): bool => $this->getRecord()->deleted_by_student_at !== null
                    && (auth()->user()?->can('restore', $this->getRecord()) ?? false))
                ->requiresConfirmation()
                ->modalHeading('Restore conversation')
                ->modalDescription('Make this student-hidden conversation visible to the student again.')
                ->modalSubmitActionLabel('Restore')
                ->action(function (): void {
                    ChatConversationResource::restoreConversation($this->getRecord());
                }),

            Actions\Action::make('hardDelete')
                ->label('Hard delete')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => auth()->user()?->can('delete', $this->getRecord()) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Permanently delete conversation')
                ->modalDescription('This permanently deletes the conversation and cascades to ALL of its messages and AI requests. This action cannot be undone.')
                ->modalSubmitActionLabel('Delete permanently')
                ->action(function (): void {
                    $deleted = ChatConversationResource::hardDeleteConversation($this->getRecord());

                    // The record no longer exists — return to the list.
                    if ($deleted) {
                        $this->redirect(ChatConversationResource::getUrl('index'));
                    }
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Student')
                    ->schema([
                        Infolists\Components\TextEntry::make('student.full_name')
                            ->label('Name'),
                        Infolists\Components\TextEntry::make('student.student_id')
                            ->label('Student ID'),
                        Infolists\Components\TextEntry::make('student.email')
                            ->label('Email'),
                        Infolists\Components\TextEntry::make('student.faculty.name')
                            ->label('Faculty')
                            ->default('—'),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Conversation')
                    ->schema([
                        Infolists\Components\TextEntry::make('title'),

                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => $state === ChatConversation::STATUS_ACTIVE
                                ? 'success'
                                : 'gray'),

                        Infolists\Components\TextEntry::make('visibility')
                            ->state(fn (ChatConversation $record): string => $record->deleted_by_student_at === null
                                ? 'Active'
                                : 'Student-Hidden')
                            ->badge()
                            ->color(fn (string $state): string => $state === 'Active' ? 'success' : 'warning'),

                        Infolists\Components\TextEntry::make('deleted_by_student_at')
                            ->label('Hidden by student at')
                            ->dateTime()
                            ->placeholder('—'),

                        Infolists\Components\TextEntry::make('last_message_at')
                            ->label('Last message at')
                            ->dateTime()
                            ->placeholder('—'),

                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Created at')
                            ->dateTime(),
                    ])
                    ->columns(2),
            ]);
    }
}
