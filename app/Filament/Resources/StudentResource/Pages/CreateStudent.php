<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\StudentResource;
use App\Mail\StudentAccountCreatedMail;
use App\Models\Faculty;
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

        // Admin-created accounts use a temporary password. Force the student to
        // set their own password on first login before normal features unlock.
        $data['password_must_be_changed'] = true;
        $data['password_changed_at'] = null;

        // Server-side source of truth: credits_required always mirrors the
        // selected faculty/program credit_hours, regardless of any submitted
        // value. Falls back to the submitted value only for legacy faculties
        // that have no credit_hours.
        $creditHours = Faculty::find($data['faculty_id'] ?? null)?->credit_hours;
        if ($creditHours !== null) {
            $data['credits_required'] = $creditHours;
        }

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
                // Record is kept; only the welcome email failed. The account is
                // flagged password_must_be_changed = true above, so the student
                // is still forced to set their own password on first login even
                // if this welcome email never arrived.
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
