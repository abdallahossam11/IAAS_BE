<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StudentResource\Pages;
use App\Models\Faculty;
use App\Models\Student;
use App\Support\Security\PasswordRules;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class StudentResource extends Resource
{
    protected static ?string $model = Student::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Academic';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('student_id')
                    ->label('Student ID')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),

                Forms\Components\TextInput::make('full_name')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),

                Forms\Components\DatePicker::make('date_of_birth')
                    ->label('Date of Birth')
                    // Required for new admin-created students; left optional on
                    // edit so legacy students with a null DOB can still be saved.
                    ->required(fn (string $operation): bool => $operation === 'create')
                    // Must be a valid past/present date — never in the future.
                    ->maxDate(today())
                    ->displayFormat('d M Y'),

                Forms\Components\TextInput::make('password')
                    ->password()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->rules(fn (string $operation): array => $operation === 'create'
                        ? PasswordRules::requiredStrong()
                        : PasswordRules::optionalStrong()
                    )
                    ->maxLength(255),

                Forms\Components\Select::make('faculty_id')
                    ->label('Faculty / Program')
                    ->relationship('faculty', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    // Auto-fill credits_required from the selected program's
                    // credit_hours. Server-side enforcement in the Create/Edit
                    // page hooks is the source of truth if this live update is
                    // bypassed.
                    ->live()
                    ->afterStateUpdated(function ($state, Forms\Set $set): void {
                        $creditHours = Faculty::find($state)?->credit_hours;

                        if ($creditHours !== null) {
                            $set('credits_required', $creditHours);
                        }
                    }),

                Forms\Components\TextInput::make('gpa')
                    ->label('GPA')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(4)
                    ->step(0.01)
                    ->default(0),

                Forms\Components\TextInput::make('credits_completed')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0),

                Forms\Components\TextInput::make('credits_required')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    // Derived from the selected faculty/program; read-only so it
                    // cannot be hand-edited. readOnly (not disabled) keeps the
                    // value dehydrated/submitted.
                    ->readOnly()
                    ->helperText('Automatically set from the selected faculty/program credit hours.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('student_id')
                    ->label('Student ID')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('full_name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->searchable(),

                Tables\Columns\TextColumn::make('date_of_birth')
                    ->label('Date of Birth')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('faculty.name')
                    ->sortable(),

                Tables\Columns\TextColumn::make('gpa')
                    ->label('GPA')
                    ->sortable(),

                Tables\Columns\TextColumn::make('credits_completed')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('credits_required')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('faculty_id')
                    ->relationship('faculty', 'name')
                    ->label('Faculty'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Skip students that have chatbot history; delete the rest.
                    // This prevents the DB restrictOnDelete from raising a raw
                    // QueryException and tells the admin what was skipped.
                    Tables\Actions\DeleteBulkAction::make()
                        ->action(function (Collection $records): void {
                            $protected = $records->filter(
                                fn (Student $student): bool => $student->hasChatHistory()
                            );
                            $deletable = $records->reject(
                                fn (Student $student): bool => $student->hasChatHistory()
                            );

                            $deletable->each(fn (Student $student) => $student->delete());

                            if ($protected->isNotEmpty()) {
                                Notification::make()
                                    ->title('Some students were not deleted')
                                    ->body($protected->count().' student(s) with saved chatbot history were skipped. Delete their conversations first.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title('Selected students deleted')
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
            'index' => Pages\ListStudents::route('/'),
            'create' => Pages\CreateStudent::route('/create'),
            'edit' => Pages\EditStudent::route('/{record}/edit'),
        ];
    }
}
