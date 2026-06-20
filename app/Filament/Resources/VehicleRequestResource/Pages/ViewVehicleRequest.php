<?php

namespace App\Filament\Resources\VehicleRequestResource\Pages;

use App\Filament\Resources\VehicleRequestResource;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewVehicleRequest extends ViewRecord
{
    protected static string $resource = VehicleRequestResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Student Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('student.full_name')
                            ->label('Student Name'),
                        Infolists\Components\TextEntry::make('student.student_id')
                            ->label('Student ID'),
                        Infolists\Components\TextEntry::make('student.email')
                            ->label('Email'),
                        Infolists\Components\TextEntry::make('student.faculty.name')
                            ->label('Faculty'),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Vehicle Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('vehicle_type'),
                        Infolists\Components\TextEntry::make('vehicle_model'),
                        Infolists\Components\TextEntry::make('vehicle_color'),
                        Infolists\Components\TextEntry::make('plate_number'),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Request Status')
                    ->schema([
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'pending' => 'warning',
                                'approved' => 'success',
                                'rejected' => 'danger',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('admin.name')
                            ->label('Reviewed By')
                            ->default('—'),
                        Infolists\Components\TextEntry::make('approved_at')
                            ->dateTime()
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('semester_start_date')
                            ->date()
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('semester_end_date')
                            ->date()
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('rejection_reason')
                            ->default('—')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Submitted At')
                            ->dateTime(),
                    ])
                    ->columns(2),
            ]);
    }
}
