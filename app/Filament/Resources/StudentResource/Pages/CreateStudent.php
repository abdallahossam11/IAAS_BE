<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\StudentResource;
use App\Mail\StudentAccountCreatedMail;
use App\Support\Security\AuditLog;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CreateStudent extends CreateRecord
{
    protected static string $resource = StudentResource::class;

    private string $capturedPlainPassword = '';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Capture the plain text password before the model's 'hashed' cast processes it.
        $this->capturedPlainPassword = $data['password'] ?? '';

        return $data;
    }

    protected function afterCreate(): void
    {
        $student = $this->record;

        AuditLog::info('student_account_created', [
            'actor_admin_id' => auth()->id(),
            'target_student_id' => $student->id,
            'student_id_field' => $student->student_id,
        ]);

        if ($student->email && $this->capturedPlainPassword !== '') {
            try {
                Mail::to($student->email)
                    ->send(new StudentAccountCreatedMail($student, $this->capturedPlainPassword));
            } catch (\Throwable $e) {
                // Record is kept; only the welcome email failed.
                // TODO: must_change_password flow — consider adding this flag
                //       so students are forced to set their own password after
                //       admin-created accounts, even if the email never arrived.
                Log::error('Failed to send StudentAccountCreatedMail', [
                    'student_id' => $student->id,
                    'error' => $e->getMessage(),
                ]);

                Notification::make()
                    ->title('Account created — email delivery failed')
                    ->body('The student account was saved, but the welcome email could not be sent. Please share the credentials manually.')
                    ->warning()
                    ->send();
            }
        }
    }
}
