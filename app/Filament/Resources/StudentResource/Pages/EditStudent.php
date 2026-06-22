<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\StudentResource;
use App\Models\Faculty;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStudent extends EditRecord
{
    protected static string $resource = StudentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * The password field is the admin "reset password" path: it is dehydrated
     * only when the admin enters a new value. When that happens, treat the new
     * password as temporary and require the student to change it on next login.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (filled($data['password'] ?? null)) {
            $data['password_must_be_changed'] = true;
            $data['password_changed_at'] = null;
        }

        // Server-side source of truth: keep credits_required in sync with the
        // selected faculty/program credit_hours on every save (e.g. when the
        // admin switches the student to a different program). Falls back to the
        // submitted value only for legacy faculties with no credit_hours.
        $creditHours = Faculty::find($data['faculty_id'] ?? null)?->credit_hours;
        if ($creditHours !== null) {
            $data['credits_required'] = $creditHours;
        }

        return $data;
    }
}
