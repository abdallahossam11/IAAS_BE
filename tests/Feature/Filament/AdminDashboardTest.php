<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\AdminResource\Pages\CreateAdmin;
use App\Filament\Resources\AdminResource\Pages\EditAdmin;
use App\Filament\Resources\AdminResource\Pages\ListAdmins;
use App\Models\Admin;
use App\Policies\AdminPolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression tests for the Admin Filament dashboard.
 *
 * Policy (AdminPolicy):
 *   super_admin only → list, view, create, update
 *   delete → super_admin AND not root admin AND not self
 *   deleteAny → super_admin (but bulk delete is intentionally absent)
 *   All other roles → 403 on all Admin resource pages
 *
 * Root admin protection (email = 'admin@galala.edu.eg'):
 *   - Table row DeleteAction is hidden via ->hidden() closure.
 *   - EditAdmin header DeleteAction is also hidden via ->hidden() closure.
 *   - Email and role fields are disabled on the edit form.
 *   - mutateFormDataBeforeSave() forces email and role back even if bypassed.
 *
 * Bulk delete:
 *   Intentionally removed from bulkActions([]) to protect the root admin.
 *   No DeleteBulkAction is registered.
 *
 * Form constraints:
 *   name     — required
 *   email    — required, unique (ignoreRecord on edit)
 *   password — required on create; dehydrated only if non-empty on edit
 *   role     — required; Select from [super_admin, vehicle_admin,
 *              academic_admin, support_admin]
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT_EMAIL = 'admin@galala.edu.eg';

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function loginAs(Admin $admin): void
    {
        $this->actingAs($admin, 'web');
    }

    private function superAdmin(): Admin
    {
        return Admin::factory()->superAdmin()->create();
    }

    private function validFormData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Admin',
            'email' => 'newadmin@example.com',
            'password' => 'SecureAdmin1!',
            'role' => 'vehicle_admin',
        ], $overrides);
    }

    // =========================================================================
    // A) Role access — list page
    // =========================================================================

    public function test_super_admin_can_access_admin_list(): void
    {
        $this->loginAs($this->superAdmin());

        $this->get('/admin/admins')->assertOk();
    }

    public function test_support_admin_cannot_access_admin_list(): void
    {
        $this->loginAs(Admin::factory()->supportAdmin()->create());

        $this->get('/admin/admins')->assertForbidden();
    }

    public function test_academic_admin_cannot_access_admin_list(): void
    {
        $this->loginAs(Admin::factory()->academicAdmin()->create());

        $this->get('/admin/admins')->assertForbidden();
    }

    public function test_vehicle_admin_cannot_access_admin_list(): void
    {
        $this->loginAs(Admin::factory()->vehicleAdmin()->create());

        $this->get('/admin/admins')->assertForbidden();
    }

    // =========================================================================
    // B) Role access — create page
    // =========================================================================

    public function test_super_admin_can_access_create_admin_page(): void
    {
        $this->loginAs($this->superAdmin());

        $this->get('/admin/admins/create')->assertOk();
    }

    public function test_non_super_admin_cannot_access_create_admin_page(): void
    {
        $this->loginAs(Admin::factory()->academicAdmin()->create());

        $this->get('/admin/admins/create')->assertForbidden();
    }

    // =========================================================================
    // C) Create admin
    // =========================================================================

    public function test_super_admin_can_create_admin(): void
    {
        $this->loginAs($this->superAdmin());

        Livewire::test(CreateAdmin::class)
            ->fillForm($this->validFormData())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('admins', [
            'email' => 'newadmin@example.com',
            'role' => 'vehicle_admin',
        ]);
    }

    public function test_create_hashes_password(): void
    {
        $this->loginAs($this->superAdmin());

        Livewire::test(CreateAdmin::class)
            ->fillForm($this->validFormData(['password' => 'PlainText-Pa55!']))
            ->call('create')
            ->assertHasNoFormErrors();

        $admin = Admin::where('email', 'newadmin@example.com')->firstOrFail();

        $this->assertNotSame('PlainText-Pa55!', $admin->password);
        $this->assertTrue(Hash::check('PlainText-Pa55!', $admin->password));
    }

    public function test_create_requires_name(): void
    {
        $this->loginAs($this->superAdmin());

        Livewire::test(CreateAdmin::class)
            ->fillForm($this->validFormData(['name' => '']))
            ->call('create')
            ->assertHasFormErrors(['name']);
    }

    public function test_create_requires_email(): void
    {
        $this->loginAs($this->superAdmin());

        Livewire::test(CreateAdmin::class)
            ->fillForm($this->validFormData(['email' => '']))
            ->call('create')
            ->assertHasFormErrors(['email']);
    }

    public function test_create_requires_password(): void
    {
        $this->loginAs($this->superAdmin());

        Livewire::test(CreateAdmin::class)
            ->fillForm($this->validFormData(['password' => '']))
            ->call('create')
            ->assertHasFormErrors(['password']);
    }

    public function test_create_requires_role(): void
    {
        $this->loginAs($this->superAdmin());

        Livewire::test(CreateAdmin::class)
            ->fillForm($this->validFormData(['role' => '']))
            ->call('create')
            ->assertHasFormErrors(['role']);
    }

    public function test_create_rejects_duplicate_email(): void
    {
        Admin::factory()->create(['email' => 'taken@example.com']);
        $this->loginAs($this->superAdmin());

        Livewire::test(CreateAdmin::class)
            ->fillForm($this->validFormData(['email' => 'taken@example.com']))
            ->call('create')
            ->assertHasFormErrors(['email']);
    }

    // =========================================================================
    // D) Edit admin
    // =========================================================================

    public function test_super_admin_can_edit_non_root_admin(): void
    {
        $actor = $this->superAdmin();
        $this->loginAs($actor);

        $target = Admin::factory()->vehicleAdmin()->create();

        Livewire::test(EditAdmin::class, ['record' => $target->getKey()])
            ->fillForm(['name' => 'Updated Name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Updated Name', $target->fresh()->name);
    }

    public function test_edit_with_blank_password_keeps_old_password(): void
    {
        $actor = $this->superAdmin();
        $this->loginAs($actor);

        $target = Admin::factory()->create(['password' => 'original-password']);
        $oldHash = $target->fresh()->password;

        Livewire::test(EditAdmin::class, ['record' => $target->getKey()])
            ->fillForm(['password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        // dehydrated(fn (?string $state) => filled($state)) excludes empty
        // password from the save payload; the old hash must be unchanged.
        $this->assertSame($oldHash, $target->fresh()->password);
    }

    public function test_edit_with_new_password_hashes_new_password(): void
    {
        $actor = $this->superAdmin();
        $this->loginAs($actor);

        $target = Admin::factory()->create(['password' => 'old-password']);

        Livewire::test(EditAdmin::class, ['record' => $target->getKey()])
            ->fillForm(['password' => 'NewPa55word!'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $target->fresh();
        $this->assertTrue(Hash::check('NewPa55word!', $fresh->password));
        $this->assertFalse(Hash::check('old-password', $fresh->password));
    }

    public function test_edit_allows_same_email_for_own_record(): void
    {
        // unique(ignoreRecord: true) excludes the current record's email.
        $actor = $this->superAdmin();
        $this->loginAs($actor);

        $target = Admin::factory()->create();

        Livewire::test(EditAdmin::class, ['record' => $target->getKey()])
            ->fillForm(['email' => $target->email])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    // =========================================================================
    // E) Root admin backend protection
    // =========================================================================

    public function test_root_admin_role_and_email_are_preserved_after_save(): void
    {
        // mutateFormDataBeforeSave() in EditAdmin forces the root admin's
        // email and role back to their correct values even if the UI somehow
        // passes different data (email and role fields are also ->disabled()).
        $actor = $this->superAdmin();
        $this->loginAs($actor);

        $rootAdmin = Admin::factory()->superAdmin()->create(['email' => self::ROOT_EMAIL]);

        Livewire::test(EditAdmin::class, ['record' => $rootAdmin->getKey()])
            ->fillForm(['name' => 'Changed Name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $rootAdmin->fresh();
        $this->assertSame(self::ROOT_EMAIL, $fresh->email);
        $this->assertSame('super_admin', $fresh->role);
        $this->assertSame('Changed Name', $fresh->name);
    }

    // =========================================================================
    // F) Delete protection — root admin
    // =========================================================================

    public function test_root_admin_table_delete_action_is_hidden(): void
    {
        // ->hidden(fn (Admin $record) => $record->email === 'admin@galala.edu.eg')
        // on the table row DeleteAction hides it for the root admin.
        $this->loginAs($this->superAdmin());
        $rootAdmin = Admin::factory()->superAdmin()->create(['email' => self::ROOT_EMAIL]);

        Livewire::test(ListAdmins::class)
            ->assertTableActionHidden('delete', $rootAdmin);
    }

    public function test_root_admin_edit_page_delete_action_is_hidden(): void
    {
        // EditAdmin::getHeaderActions() hides DeleteAction for the root admin.
        $actor = $this->superAdmin();
        $this->loginAs($actor);

        $rootAdmin = Admin::factory()->superAdmin()->create(['email' => self::ROOT_EMAIL]);

        Livewire::test(EditAdmin::class, ['record' => $rootAdmin->getKey()])
            ->assertActionHidden('delete');
    }

    // =========================================================================
    // G) Delete — non-root admin
    // =========================================================================

    public function test_super_admin_can_delete_non_root_admin_via_table_action(): void
    {
        $actor = $this->superAdmin();
        $this->loginAs($actor);

        $target = Admin::factory()->vehicleAdmin()->create();

        Livewire::test(ListAdmins::class)
            ->callTableAction('delete', $target);

        $this->assertDatabaseMissing('admins', ['id' => $target->id]);
    }

    // =========================================================================
    // H) Policy unit checks
    // =========================================================================

    public function test_policy_blocks_root_admin_delete(): void
    {
        $actor = $this->superAdmin();
        $rootAdmin = Admin::factory()->superAdmin()->create(['email' => self::ROOT_EMAIL]);

        $this->assertFalse((new AdminPolicy)->delete($actor, $rootAdmin));
    }

    public function test_policy_blocks_self_delete(): void
    {
        // AdminPolicy::delete() checks $user->id !== $model->id.
        $admin = $this->superAdmin();

        $this->assertFalse((new AdminPolicy)->delete($admin, $admin));
    }

    public function test_policy_allows_super_admin_to_delete_other_non_root_admin(): void
    {
        $actor = $this->superAdmin();
        $target = Admin::factory()->vehicleAdmin()->create();

        $this->assertTrue((new AdminPolicy)->delete($actor, $target));
    }

    // =========================================================================
    // I) No bulk delete
    // =========================================================================

    public function test_no_bulk_delete_action_is_registered(): void
    {
        // Bulk delete was intentionally removed from AdminResource to protect
        // the root admin account. There should be no DeleteBulkAction.
        $this->loginAs($this->superAdmin());

        Livewire::test(ListAdmins::class)
            ->assertTableBulkActionDoesNotExist('delete');
    }

    // =========================================================================
    // J) Password policy
    // =========================================================================

    public function test_weak_admin_password_rejected_on_create(): void
    {
        $this->loginAs($this->superAdmin());

        Livewire::test(CreateAdmin::class)
            ->fillForm($this->validFormData(['password' => 'secret1234']))
            ->call('create')
            ->assertHasFormErrors(['password']);
    }

    public function test_strong_admin_password_accepted_on_create(): void
    {
        $this->loginAs($this->superAdmin());

        Livewire::test(CreateAdmin::class)
            ->fillForm($this->validFormData(['password' => 'SecureAdmin1!']))
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_blank_password_on_edit_keeps_old_password_unchanged(): void
    {
        $actor = $this->superAdmin();
        $this->loginAs($actor);

        $target = Admin::factory()->create(['password' => 'original-password']);
        $oldHash = $target->fresh()->password;

        Livewire::test(EditAdmin::class, ['record' => $target->getKey()])
            ->fillForm(['password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($oldHash, $target->fresh()->password);
    }

    public function test_weak_new_password_on_edit_rejected(): void
    {
        $actor = $this->superAdmin();
        $this->loginAs($actor);

        $target = Admin::factory()->create();

        Livewire::test(EditAdmin::class, ['record' => $target->getKey()])
            ->fillForm(['password' => 'weak'])
            ->call('save')
            ->assertHasFormErrors(['password']);
    }

    public function test_strong_new_password_on_edit_accepted(): void
    {
        $actor = $this->superAdmin();
        $this->loginAs($actor);

        $target = Admin::factory()->create();

        Livewire::test(EditAdmin::class, ['record' => $target->getKey()])
            ->fillForm(['password' => 'UpdatedPa55!'])
            ->call('save')
            ->assertHasNoFormErrors();
    }
}
