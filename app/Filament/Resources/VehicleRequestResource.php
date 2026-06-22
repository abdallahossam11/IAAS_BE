<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VehicleRequestResource\Pages;
use App\Models\VehicleRequest;
use App\Support\Security\AuditLog;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class VehicleRequestResource extends Resource
{
    protected static ?string $model = VehicleRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Vehicle Management';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('student.full_name')
                    ->label('Student Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('student.student_id')
                    ->label('Student ID')
                    ->searchable(),

                Tables\Columns\TextColumn::make('vehicle_type')
                    ->sortable(),

                Tables\Columns\TextColumn::make('vehicle_model')
                    ->sortable(),

                Tables\Columns\TextColumn::make('vehicle_color'),

                Tables\Columns\TextColumn::make('plate_number')
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('semester_start_date')
                    ->date()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('semester_end_date')
                    ->date()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('approved_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('rejection_reason')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('admin.name')
                    ->label('Reviewed By')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                // ── Approve Action ──
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (VehicleRequest $record): bool => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\DatePicker::make('semester_start_date')
                            ->label('Semester Start Date')
                            ->required(),
                        Forms\Components\DatePicker::make('semester_end_date')
                            ->label('Semester End Date')
                            ->required()
                            ->after('semester_start_date'),
                    ])
                    ->action(function (VehicleRequest $record, array $data): void {
                        // Guard: only allow transition from pending
                        if ($record->status !== 'pending') {
                            Notification::make()
                                ->title('Cannot approve')
                                ->body('This request is no longer pending.')
                                ->danger()
                                ->send();

                            return;
                        }

                        // Date order guard: semester_end_date must be after semester_start_date
                        if (
                            ! empty($data['semester_start_date'])
                            && ! empty($data['semester_end_date'])
                            && $data['semester_end_date'] <= $data['semester_start_date']
                        ) {
                            Notification::make()
                                ->title('Invalid date range')
                                ->body('Semester end date must be after semester start date.')
                                ->danger()
                                ->send();

                            return;
                        }

                        // Check if student already has an active approved permit
                        $today = Carbon::today();
                        $hasActivePermit = VehicleRequest::where('student_id', $record->student_id)
                            ->where('id', '!=', $record->id)
                            ->where('status', 'approved')
                            ->whereNotNull('semester_end_date')
                            ->where('semester_end_date', '>=', $today)
                            ->exists();

                        if ($hasActivePermit) {
                            Notification::make()
                                ->title('Cannot approve')
                                ->body('This student already has an active approved vehicle permit.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->update([
                            'status' => 'approved',
                            'admin_id' => auth()->id(),
                            'approved_at' => now(),
                            'semester_start_date' => $data['semester_start_date'],
                            'semester_end_date' => $data['semester_end_date'],
                            'rejection_reason' => null,
                        ]);

                        AuditLog::info('vehicle_request_approved', [
                            'actor_admin_id' => auth()->id(),
                            'vehicle_request_id' => $record->id,
                            'student_id' => $record->student_id,
                            'semester_start' => $data['semester_start_date'],
                            'semester_end' => $data['semester_end_date'],
                        ]);

                        Notification::make()
                            ->title('Request approved')
                            ->body('Vehicle request has been approved successfully.')
                            ->success()
                            ->send();
                    }),

                // ── Reject Action ──
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (VehicleRequest $record): bool => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('rejection_reason')
                            ->label('Rejection Reason')
                            ->required()
                            ->maxLength(1000),
                    ])
                    ->action(function (VehicleRequest $record, array $data): void {
                        // Guard: only allow transition from pending
                        if ($record->status !== 'pending') {
                            Notification::make()
                                ->title('Cannot reject')
                                ->body('This request is no longer pending.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->update([
                            'status' => 'rejected',
                            'admin_id' => auth()->id(),
                            'rejection_reason' => $data['rejection_reason'],
                            'approved_at' => null,
                            'semester_start_date' => null,
                            'semester_end_date' => null,
                        ]);

                        AuditLog::warning('vehicle_request_rejected', [
                            'actor_admin_id' => auth()->id(),
                            'vehicle_request_id' => $record->id,
                            'student_id' => $record->student_id,
                            'reason' => $data['rejection_reason'],
                        ]);

                        Notification::make()
                            ->title('Request rejected')
                            ->body('Vehicle request has been rejected.')
                            ->warning()
                            ->send();
                    }),

                // ── Delete Action ──
                // Authorized by VehicleRequestPolicy::delete() (super_admin /
                // vehicle_admin only). Hard delete: removing an approved row also
                // revokes the student's active permit, since the permit is the
                // same vehicle_requests row.
                Tables\Actions\DeleteAction::make()
                    ->label('Delete')
                    ->requiresConfirmation()
                    ->modalHeading('Delete vehicle request/permit')
                    ->modalDescription(fn (VehicleRequest $record): string => $record->status === 'approved'
                        ? 'This will permanently delete this vehicle request/permit. If this is an approved permit, deleting it will revoke the student\'s vehicle access.'
                        : 'This will permanently delete this vehicle request/permit.')
                    ->before(function (VehicleRequest $record): void {
                        // Logged while the record still exists so the audit entry
                        // survives the deletion.
                        AuditLog::warning('vehicle_request_deleted', [
                            'actor_admin_id' => auth()->id(),
                            'vehicle_request_id' => $record->id,
                            'student_id' => $record->student_id,
                            'status' => $record->status,
                            'plate_number' => $record->plate_number,
                            'vehicle_type' => $record->vehicle_type,
                            'vehicle_model' => $record->vehicle_model,
                        ]);
                    }),
            ])
            ->bulkActions([]);
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
            'index' => Pages\ListVehicleRequests::route('/'),
            'view' => Pages\ViewVehicleRequest::route('/{record}'),
        ];
    }
}
