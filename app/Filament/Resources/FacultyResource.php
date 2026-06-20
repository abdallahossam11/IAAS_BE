<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FacultyResource\Pages;
use App\Models\Faculty;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class FacultyResource extends Resource
{
    protected static ?string $model = Faculty::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'Academic';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('students_count')
                    ->counts('students')
                    ->label('Students'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Skip faculties whose students have chatbot history; delete the rest.
                    // The DB's restrictOnDelete on chat_conversations.student_id would
                    // otherwise cause a QueryException when the cascade hits those students.
                    Tables\Actions\DeleteBulkAction::make()
                        ->action(function (Collection $records): void {
                            $protected = $records->filter(
                                fn (Faculty $faculty): bool => $faculty->hasStudentsWithChatHistory()
                            );
                            $deletable = $records->reject(
                                fn (Faculty $faculty): bool => $faculty->hasStudentsWithChatHistory()
                            );

                            $deletable->each(fn (Faculty $faculty) => $faculty->delete());

                            if ($protected->isNotEmpty()) {
                                Notification::make()
                                    ->title('Some faculties were not deleted')
                                    ->body(
                                        $protected->count().' '.
                                        ($protected->count() === 1 ? 'faculty has' : 'faculties have').
                                        ' students with saved chatbot history and were skipped. Delete the conversations first.'
                                    )
                                    ->warning()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title('Selected faculties deleted')
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFaculties::route('/'),
            'create' => Pages\CreateFaculty::route('/create'),
            'edit' => Pages\EditFaculty::route('/{record}/edit'),
        ];
    }
}
