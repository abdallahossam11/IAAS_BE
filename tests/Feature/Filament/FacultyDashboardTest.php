<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\FacultyResource\Pages\CreateFaculty;
use App\Filament\Resources\FacultyResource\Pages\EditFaculty;
use App\Filament\Resources\FacultyResource\Pages\ListFaculties;
use App\Models\Admin;
use App\Models\ChatConversation;
use App\Models\Faculty;
use App\Models\Student;
use App\Policies\FacultyPolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression tests for the Faculty Filament dashboard.
 *
 * Policy (FacultyPolicy):
 *   super_admin    → all operations (list, create, update, delete, deleteAny)
 *   academic_admin → all operations
 *   support_admin  → 403
 *   vehicle_admin  → 403
 *
 * FacultyResource table actions:
 *   Row actions: EditAction ONLY — no row-level DeleteAction.
 *   Delete is available via: EditFaculty header DeleteAction OR bulk delete.
 *
 * BulkAction:
 *   Standard DeleteBulkAction (no custom logic; fully policy-based).
 *
 * Faculty form constraints:
 *   name — required, unique (ignoreRecord on edit).
 *
 * Faculty delete and the students relationship:
 *   students.faculty_id has cascadeOnDelete() → faculty deletion also deletes
 *   its students at the DB level.
 *
 * Faculty delete and chat history guard (patched):
 *   FacultyPolicy::delete() returns false when Faculty::hasStudentsWithChatHistory()
 *   is true, preventing the cascade from reaching the restrictOnDelete constraint on
 *   chat_conversations.student_id. The single delete action is hidden by the policy;
 *   the bulk delete action skips protected faculties and sends a warning notification.
 */
class FacultyDashboardTest extends TestCase
{
    use RefreshDatabase;

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

    private function validFacultyData(array $overrides = []): array
    {
        return array_merge([
            'sector' => 'Sciences Sector',
            'field' => 'Computer Science',
            'name' => 'Computer Science Program',
            'credit_hours' => 127,
        ], $overrides);
    }

    // =========================================================================
    // A) Role access — list page
    // =========================================================================

    public function test_super_admin_can_access_faculty_list(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        $this->get('/admin/faculties')->assertOk();
    }

    public function test_academic_admin_can_access_faculty_list(): void
    {
        $this->loginAs(Admin::factory()->academicAdmin()->create());

        $this->get('/admin/faculties')->assertOk();
    }

    public function test_support_admin_cannot_access_faculty_list(): void
    {
        $this->loginAs(Admin::factory()->supportAdmin()->create());

        $this->get('/admin/faculties')->assertForbidden();
    }

    public function test_vehicle_admin_cannot_access_faculty_list(): void
    {
        $this->loginAs(Admin::factory()->vehicleAdmin()->create());

        $this->get('/admin/faculties')->assertForbidden();
    }

    // =========================================================================
    // B) Role access — create page
    // =========================================================================

    public function test_super_admin_can_access_create_faculty_page(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        $this->get('/admin/faculties/create')->assertOk();
    }

    public function test_academic_admin_can_access_create_faculty_page(): void
    {
        $this->loginAs(Admin::factory()->academicAdmin()->create());

        $this->get('/admin/faculties/create')->assertOk();
    }

    public function test_support_admin_cannot_access_create_faculty_page(): void
    {
        $this->loginAs(Admin::factory()->supportAdmin()->create());

        $this->get('/admin/faculties/create')->assertForbidden();
    }

    // =========================================================================
    // C) Create faculty
    // =========================================================================

    public function test_super_admin_can_create_faculty(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        Livewire::test(CreateFaculty::class)
            ->fillForm($this->validFacultyData(['name' => 'Engineering Faculty']))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('faculties', ['name' => 'Engineering Faculty']);
    }

    public function test_academic_admin_can_create_faculty(): void
    {
        $this->loginAs(Admin::factory()->academicAdmin()->create());

        Livewire::test(CreateFaculty::class)
            ->fillForm($this->validFacultyData(['name' => 'Science Faculty']))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('faculties', ['name' => 'Science Faculty']);
    }

    public function test_create_requires_name(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        Livewire::test(CreateFaculty::class)
            ->fillForm($this->validFacultyData(['name' => '']))
            ->call('create')
            ->assertHasFormErrors(['name']);
    }

    public function test_create_rejects_duplicate_name(): void
    {
        Faculty::factory()->create(['name' => 'Engineering Faculty']);
        $this->loginAs(Admin::factory()->superAdmin()->create());

        Livewire::test(CreateFaculty::class)
            ->fillForm($this->validFacultyData(['name' => 'Engineering Faculty']))
            ->call('create')
            ->assertHasFormErrors(['name']);
    }

    // =========================================================================
    // D) Edit faculty
    // =========================================================================

    public function test_super_admin_can_edit_faculty(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());
        $faculty = Faculty::factory()->create(['name' => 'Old Name']);

        Livewire::test(EditFaculty::class, ['record' => $faculty->getKey()])
            ->fillForm(['name' => 'New Name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('New Name', $faculty->fresh()->name);
    }

    public function test_academic_admin_can_edit_faculty(): void
    {
        $this->loginAs(Admin::factory()->academicAdmin()->create());
        $faculty = Faculty::factory()->create(['name' => 'Old Name']);

        Livewire::test(EditFaculty::class, ['record' => $faculty->getKey()])
            ->fillForm(['name' => 'Academic Update'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Academic Update', $faculty->fresh()->name);
    }

    public function test_edit_allows_same_name_for_own_record(): void
    {
        // unique(ignoreRecord: true) excludes the current record's own name.
        $this->loginAs(Admin::factory()->superAdmin()->create());
        $faculty = Faculty::factory()->create();

        Livewire::test(EditFaculty::class, ['record' => $faculty->getKey()])
            ->fillForm(['name' => $faculty->name])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_edit_rejects_duplicate_name(): void
    {
        Faculty::factory()->create(['name' => 'Taken Name']);
        $faculty = Faculty::factory()->create(['name' => 'Other Name']);
        $this->loginAs(Admin::factory()->superAdmin()->create());

        Livewire::test(EditFaculty::class, ['record' => $faculty->getKey()])
            ->fillForm(['name' => 'Taken Name'])
            ->call('save')
            ->assertHasFormErrors(['name']);
    }

    // =========================================================================
    // E) Delete faculty — via EditFaculty header action
    //    The FacultyResource table has NO row-level DeleteAction;
    //    single-record deletion is only available on the edit page.
    // =========================================================================

    public function test_super_admin_can_delete_faculty_with_no_students(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());
        $faculty = Faculty::factory()->create();

        Livewire::test(EditFaculty::class, ['record' => $faculty->getKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('faculties', ['id' => $faculty->id]);
    }

    public function test_academic_admin_can_delete_faculty_with_no_students(): void
    {
        $this->loginAs(Admin::factory()->academicAdmin()->create());
        $faculty = Faculty::factory()->create();

        Livewire::test(EditFaculty::class, ['record' => $faculty->getKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('faculties', ['id' => $faculty->id]);
    }

    // =========================================================================
    // F) Bulk delete
    // =========================================================================

    public function test_super_admin_can_bulk_delete_faculties(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());
        $facultyA = Faculty::factory()->create();
        $facultyB = Faculty::factory()->create();

        Livewire::test(ListFaculties::class)
            ->callTableBulkAction('delete', [$facultyA, $facultyB]);

        $this->assertDatabaseMissing('faculties', ['id' => $facultyA->id]);
        $this->assertDatabaseMissing('faculties', ['id' => $facultyB->id]);
    }

    public function test_academic_admin_can_bulk_delete_faculties(): void
    {
        $this->loginAs(Admin::factory()->academicAdmin()->create());
        $faculty = Faculty::factory()->create();

        Livewire::test(ListFaculties::class)
            ->callTableBulkAction('delete', [$faculty]);

        $this->assertDatabaseMissing('faculties', ['id' => $faculty->id]);
    }

    // =========================================================================
    // G) Faculty → students relationship safety
    // =========================================================================

    public function test_deleting_faculty_cascades_to_students_without_chat_history(): void
    {
        // students.faculty_id has cascadeOnDelete() in the migration.
        // Deleting a faculty also removes its students from the DB.
        $faculty = Faculty::factory()->create();
        $student = Student::factory()->create(['faculty_id' => $faculty->id]);

        $faculty->delete();

        $this->assertDatabaseMissing('students', ['id' => $student->id]);
        $this->assertDatabaseMissing('faculties', ['id' => $faculty->id]);
    }

    public function test_has_students_with_chat_history_returns_true_when_present(): void
    {
        $faculty = Faculty::factory()->create();
        $student = Student::factory()->create(['faculty_id' => $faculty->id]);
        ChatConversation::factory()->create(['student_id' => $student->id]);

        $this->assertTrue($faculty->hasStudentsWithChatHistory());
    }

    public function test_has_students_with_chat_history_returns_false_without_chat_history(): void
    {
        $faculty = Faculty::factory()->create();
        Student::factory()->create(['faculty_id' => $faculty->id]);

        $this->assertFalse($faculty->hasStudentsWithChatHistory());
    }

    public function test_policy_blocks_delete_when_faculty_has_students_with_chat_history(): void
    {
        $actor = Admin::factory()->superAdmin()->create();
        $faculty = Faculty::factory()->create();
        $student = Student::factory()->create(['faculty_id' => $faculty->id]);
        ChatConversation::factory()->create(['student_id' => $student->id]);

        $this->assertFalse((new FacultyPolicy)->delete($actor, $faculty));
    }

    public function test_delete_action_is_hidden_when_faculty_has_students_with_chat_history(): void
    {
        // FacultyPolicy::delete() returns false when hasStudentsWithChatHistory()
        // is true; Filament hides the header DeleteAction in response.
        $this->loginAs(Admin::factory()->superAdmin()->create());

        $faculty = Faculty::factory()->create();
        $student = Student::factory()->create(['faculty_id' => $faculty->id]);
        ChatConversation::factory()->create(['student_id' => $student->id]);

        Livewire::test(EditFaculty::class, ['record' => $faculty->getKey()])
            ->assertActionHidden('delete');
    }

    public function test_bulk_delete_skips_faculties_with_chat_history_students(): void
    {
        // The custom DeleteBulkAction on FacultyResource iterates selected records
        // and skips any faculty whose students have chat history, preventing a
        // QueryException from the DB cascade/restrict constraint pair.
        $this->loginAs(Admin::factory()->superAdmin()->create());

        $safe = Faculty::factory()->create();

        $protected = Faculty::factory()->create();
        $student = Student::factory()->create(['faculty_id' => $protected->id]);
        ChatConversation::factory()->create(['student_id' => $student->id]);

        Livewire::test(ListFaculties::class)
            ->callTableBulkAction('delete', [$safe, $protected]);

        $this->assertDatabaseMissing('faculties', ['id' => $safe->id]);
        $this->assertDatabaseHas('faculties', ['id' => $protected->id]);
    }

    public function test_faculty_list_renders_students_count(): void
    {
        // FacultyResource table uses ->counts('students') to display a count
        // column; this must not crash when faculties have students.
        $this->loginAs(Admin::factory()->superAdmin()->create());

        $faculty = Faculty::factory()->create();
        Student::factory()->count(3)->create(['faculty_id' => $faculty->id]);

        $this->get('/admin/faculties')->assertOk();
    }

    // =========================================================================
    // H) Cross-module: student creation still requires a valid faculty
    // =========================================================================

    public function test_student_factory_still_creates_a_faculty(): void
    {
        // Regression guard: StudentFactory creates a Faculty automatically
        // via the faculty_id relationship. If the factory is broken, this fails.
        $student = Student::factory()->create();

        $this->assertDatabaseHas('students', ['id' => $student->id]);
        $this->assertNotNull($student->faculty_id);
        $this->assertDatabaseHas('faculties', ['id' => $student->faculty_id]);
    }

    // =========================================================================
    // I) Credit hours — sector / field / credit_hours
    // =========================================================================

    public function test_faculty_factory_sets_sector_field_and_credit_hours(): void
    {
        $faculty = Faculty::factory()->create();

        $this->assertNotNull($faculty->sector);
        $this->assertNotNull($faculty->field);
        $this->assertIsInt($faculty->credit_hours);
        $this->assertGreaterThan(0, $faculty->credit_hours);
    }

    public function test_student_factory_credits_required_matches_faculty_credit_hours(): void
    {
        // StudentFactory must keep credits_required in sync with the related
        // faculty/program credit_hours.
        $student = Student::factory()->create();

        $this->assertSame($student->faculty->credit_hours, $student->credits_required);
    }

    public function test_create_requires_credit_hours(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        Livewire::test(CreateFaculty::class)
            ->fillForm($this->validFacultyData(['credit_hours' => null]))
            ->call('create')
            ->assertHasFormErrors(['credit_hours']);
    }

    public function test_create_saves_sector_field_and_credit_hours(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        Livewire::test(CreateFaculty::class)
            ->fillForm($this->validFacultyData([
                'sector' => 'Engineering Sector',
                'field' => 'Computer Engineering',
                'name' => 'Computer Engineering Program',
                'credit_hours' => 165,
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('faculties', [
            'name' => 'Computer Engineering Program',
            'sector' => 'Engineering Sector',
            'field' => 'Computer Engineering',
            'credit_hours' => 165,
        ]);
    }

    public function test_edit_updates_credit_hours(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());
        $faculty = Faculty::factory()->create(['credit_hours' => 120]);

        Livewire::test(EditFaculty::class, ['record' => $faculty->getKey()])
            ->fillForm(['credit_hours' => 211])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(211, $faculty->fresh()->credit_hours);
    }
}
