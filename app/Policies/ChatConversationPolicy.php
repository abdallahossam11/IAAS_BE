<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\ChatConversation;

class ChatConversationPolicy
{
    /**
     * Only super_admin and support_admin may view chatbot conversations.
     * vehicle_admin and academic_admin have no access to the chat admin area.
     */
    public function viewAny(Admin $user): bool
    {
        return $user->isSuperAdmin() || $user->isSupportAdmin();
    }

    public function view(Admin $user, ChatConversation $model): bool
    {
        return $user->isSuperAdmin() || $user->isSupportAdmin();
    }

    /**
     * Conversations are created by students via the API, never from Filament.
     */
    public function create(Admin $user): bool
    {
        return false;
    }

    /**
     * Conversation fields are never edited from the admin panel.
     */
    public function update(Admin $user, ChatConversation $model): bool
    {
        return false;
    }

    /**
     * Restoring a student-hidden conversation (Phase 6B) — super/support admin.
     */
    public function restore(Admin $user, ChatConversation $model): bool
    {
        return $user->isSuperAdmin() || $user->isSupportAdmin();
    }

    /**
     * Single permanent hard-delete (Phase 6B) — super/support admin.
     *
     * This is the role gate only. The queued/processing-AI-work protection is
     * enforced at action time, not here, so no role bypasses it.
     */
    public function delete(Admin $user, ChatConversation $model): bool
    {
        return $user->isSuperAdmin() || $user->isSupportAdmin();
    }

    /**
     * No bulk hard delete.
     */
    public function deleteAny(Admin $user): bool
    {
        return false;
    }
}
