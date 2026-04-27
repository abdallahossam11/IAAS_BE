<?php

namespace App\Filament\Resources\AdminResource\Pages;

use App\Filament\Resources\AdminResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAdmin extends EditRecord
{
    protected static string $resource = AdminResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->hidden(fn () => $this->record->email === 'admin@galala.edu.eg'),
        ];
    }

    /**
     * Force role back to super_admin for the root admin account.
     * This is a backend safeguard — even if someone bypasses the disabled UI field.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record->email === 'admin@galala.edu.eg') {
            $data['email'] = 'admin@galala.edu.eg';
            $data['role'] = 'super_admin';
        }

        return $data;
    }
}
