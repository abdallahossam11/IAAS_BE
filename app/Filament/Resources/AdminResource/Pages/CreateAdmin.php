<?php

namespace App\Filament\Resources\AdminResource\Pages;

use App\Filament\Resources\AdminResource;
use App\Mail\AdminAccountCreatedMail;
use App\Support\Security\AuditLog;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CreateAdmin extends CreateRecord
{
    protected static string $resource = AdminResource::class;

    private string $capturedPlainPassword = '';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Capture the plain text password before the model's 'hashed' cast processes it.
        $this->capturedPlainPassword = $data['password'] ?? '';

        return $data;
    }

    protected function afterCreate(): void
    {
        $admin = $this->record;

        AuditLog::info('admin_account_created', [
            'actor_admin_id' => auth()->id(),
            'target_admin_id' => $admin->id,
            'target_admin_email' => $admin->email,
        ]);

        if ($admin->email && $this->capturedPlainPassword !== '') {
            try {
                Mail::to($admin->email)
                    ->send(new AdminAccountCreatedMail($admin, $this->capturedPlainPassword));
            } catch (\Throwable $e) {
                // Record is kept; only the welcome email failed.
                Log::error('Failed to send AdminAccountCreatedMail', [
                    'admin_id' => $admin->id,
                    'error' => $e->getMessage(),
                ]);

                Notification::make()
                    ->title('Account created — email delivery failed')
                    ->body('The admin account was saved, but the welcome email could not be sent. Please share the credentials manually.')
                    ->warning()
                    ->send();
            }
        }
    }
}
