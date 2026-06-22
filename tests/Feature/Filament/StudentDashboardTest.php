<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Models\Admin;
use App\Models\ChatConversation;
use App\Models\Faculty;
use App\Models\Student;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression tests for the Student Filament dashboard.
 *
 * Policy (StudentPolicy):
 *   super_admin    → list, view, create, update, delete (when no chat history)
 *   academic_admin → list, view, create, update, delete (when no chat history)
 *   support_admin  → denied on all student resources
 *   vehicle_admin  → denied on all student resources
 *
 * Form constraints:
 *   student_id — required, unique (ignoreRecord on edit)
 *   full_name  — required
 *   email      — required, unique (ignoreRecord on edit)
 *   password   — required on create only; dehydrated only if non-empty on edit
 *   faculty_id — required
 *
 * Delete protection:
 *   Single record: StudentPolicy::delete() returns false when hasChatHistory()
 *   is true; DeleteAction becomes hidden in EditStudent.
 *   hasChatHistory() — ANY chat_conversations row, regardless of
 *   deleted_by_student_at (student-hidden chats still block deletion).
 *   Bulk delete: skips protected students; notifies admin of skipped count.
 *
 * Faculty relationship:
 *   faculty_id is NOT NULL; cascadeOnDelete() means deleting a faculty also
 *   deletes its students.
 */
class StudentDashboardTest extends TestCase
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

    private function validFormData(array $overrides = []): array
    {
        return array_merge([
            'student_id' => 'GU-20240001',
            'full_name' => 'Ahmed Hassan',
            'email' => 'ahmed@example.com',
            'date_of_birth' => '2000-05-15',
            'password' => 'SecureStudent1!',
            'faculty_id' => Faculty::factory()->create()->id,
            'gpa' => 3.5,
            'credits_completed' => 60,
            'credits_required' => 140,
        ], $overrides);
    }

    // =========================================================================
    // A) Role access — list page
    // =========================================================================

    public function test_super_admin_can_access_student_list(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        $this->get('/admin/students')->assertOk();
    }

    public function test_academic_admin_can_access_student_list(): void
    {
        $this->loginAs(Admin::factory()->academicAdmin()->create());

        $this->get('/admin/students')->assertOk();
    }

    public function test_support_admin_cannot_access_student_list(): void
    {
        $this->loginAs(Admin::factory()->supportAdmin()->create());

        $this->get('/admin/students')->assertForbidden();
    }

    public function test_vehicle_admin_cannot_access_student_list(): void
    {
        $this->loginAs(Admin::factory()->vehicleAdmin()->create());

        $this->get('/admin/students')->assertForbidden();
    }

    // =========================================================================
    // B) Role access — create page
    // =========================================================================

    public function test_super_admin_can_access_create_student_page(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        $this->get('/admin/students/create')->assertOk();
    }

    public function test_academic_admin_can_access_create_student_page(): void
    {
        $this->loginAs(Admin::factory()->academicAdmin()->create());

        $this->get('/admin/students/create')->assertOk();
    }

    public function test_support_admin_cannot_access_create_student_page(): void
    {
        $this->loginAs(Admin::factory()->supportAdmin()->create());

        $this->get('/admin/students/create')->assertForbidden();
    }

    // =========================================================================
    // C) Create student
    // =========================================================================

    public function test_super_admin_can_create_a_student(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        Livewire::test(CreateStudent::class)
            ->fillForm($this->validFormData())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('students', [
            'student_id' => 'GU-20240001',
            'email' => 'ahmed@example.com',
        ]);
    }

    public function test_academic_admin_can_create_a_student(): void
    {
        $this->loginAs(Admin::factory()->academicAdmin()->create());

        Livewire::test(CreateStudent::class)
            ->fillForm($this->validFormData([
                'student_id' => 'GU-20240002',
                'email' => 'second@example.com',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('students', ['student_id' => 'GU-20240002']);
    }

    public function test_create_hashes_password(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        Livewire::test(CreateStudent::class)
            ->fillForm($this->validFormData(['password' => 'PlainText-Pa55!']))
            ->call('create')
            ->assertHasNoFormErrors();

        $student = Student::where('student_id', 'GU-20240001')->firstOrFail();

        $this->assertNotSame('PlainText-Pa55!', $student->password);
        $this->assertTrue(Hash::check('PlainText-Pa55!', $student->password));
    }

    // =========================================================================
    // D) Create — form validation
    // =========================================================================

    public function test_create_requires_student_id(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        Livewire::test(CreateStudent::class)
            ->fillForm($this->validFormData(['student_id' => '']))
            ->call('create')
            ->assertHasFormErrors(['student_id']);
    }

    public function test_create_requires_full_name(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        Livewire::test(CreateStudent::class)
            ->fillForm($this->validFormData(['full_name' => '']))
            ->call('create')
            ->assertHasFormErrors(['full_name']);
    }

    public function test_create_requires_email(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        Livewire::test(CreateStudent::class)
            ->fillForm($this->validFormData(['email' => '']))
            ->call('create')
            ->assertHasFormErrors(['email']);
    }

    public function test_create_requires_password(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        Livewire::test(CreateStudent::class)
            ->fillForm($this->validFormData(['password' => '']))
            ->call('create')
            ->assertHasFormErrors(['password']);
    }

    public function test_create_requires_faculty(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        $data = $this->validFormData();
        $data['faculty_id'] = null;

        Livewire::test(CreateStudent::class)
            ->fillForm($data)
            ->call('create')
            ->assertHasFormErrors(['faculty_id']);
    }

    public function test_create_rejects_duplicate_student_id(): void
    {
        Student::factory()->create(['student_id' => 'GU-20240001']);
        $this->loginAs(Admin::factory()->superAdmin()->create());

        Livewire::test(CreateStudent::class)
            ->fillForm($this->validFormData(['student_id' => 'GU-20240001']))
            ->call('create')
            ->assertHasFormErrors(['student_id']);
    }

    public function test_create_rejects_duplicate_email(): void
    {
        Student::factory()->create(['email' => 'taken@example.com']);
        $this->loginAs(Admin::factory()->superAdmin()->create());

        Livewire::test(CreateStudent::class)
            ->fillForm($this->validFormData(['email' => 'taken@example.com']))
            ->call('create')
            ->assertHasFormErrors(['email']);
    }

    // =========================================================================
    // E) Edit student
    // =========================================================================

    public function test_super_admin_can_edit_a_student(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());
        $student = Student::factory()->create();

        Livewire::test(EditStudent::class, ['record' => $student->getKey()])
            ->fillForm(['full_name' => 'Updated Name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Updated Name', $student->fresh()->full_name);
    }

    public function test_academic_admin_can_edit_a_student(): void
    {
        $this->loginAs(Admin::factory()->academicAdmin()->create());
        $student = Student::factory()->create();

        Livewire::test(EditStudent::class, ['record' => $student->getKey()])
            ->fillForm(['full_name' => 'Academic Update'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Academic Update', $student->fresh()->full_name);
    }

    public function test_edit_with_blank_password_keeps_old_password(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        $student = Student::factory()->create(['password' => 'original-password']);
        $oldHash = $student->fresh()->password;

        Livewire::test(EditStudent::class, ['record' => $student->getKey()])
            ->fillForm(['password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        // dehydrated(fn (?string $state) => filled($state)) means an empty
        // password is excluded from the save payload; old hash must be preserved.
        $this->assertSame($oldHash, $student->fresh()->password);
    }

    public function test_edit_with_new_password_updates_and_hashes_password(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        $student = Student::factory()->create(['password' => 'old-password']);

        Livewire::test(EditStudent::class, ['record' => $student->getKey()])
            ->fillForm(['password' => 'NewPa55word!'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $student->fresh();
        $this->assertTrue(Hash::check('NewPa55word!', $fresh->password));
        $this->assertFalse(Hash::check('old-password', $fresh->password));
    }

    public function test_edit_allows_same_student_id_for_own_record(): void
    {
        // unique(ignoreRecord: true) excludes the current record's own value
        // from the uniqueness check; updating without changing student_id must pass.
        $this->loginAs(Admin::factory()->superAdmin()->create());
        $student = Student::factory()->create();

        Livewire::test(EditStudent::class, ['record' => $student->getKey()])
            ->fillForm(['student_id' => $student->student_id])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    // =========================================================================
    // F) Delete protection — single record
    // =========================================================================

    public function test_super_admin_can_delete_student_with_no_chat_history(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());
        $student = Student::factory()->create();

        Livewire::test(EditStudent::class, ['record' => $student->getKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('students', ['id' => $student->id]);
    }

    public function test_delete_action_is_hidden_when_student_has_active_chat(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());
        $student = Student::factory()->create();
        ChatConversation::factory()->create(['student_id' => $student->id]);

        // Policy::delete() returns false when hasChatHistory() is true;
        // Filament hides the DeleteAction in response to the policy result.
        Livewire::test(EditStudent::class, ['record' => $student->getKey()])
            ->assertActionHidden('delete');

        $this->assertDatabaseHas('students', ['id' => $student->id]);
    }

    public function test_delete_action_is_hidden_when_student_has_hidden_chat(): void
    {
        // Student-hidden conversations (deleted_by_student_at is set) still count
        // as chat history — hasChatHistory() checks existence, not visibility.
        $this->loginAs(Admin::factory()->superAdmin()->create());
        $student = Student::factory()->create();
        ChatConversation::factory()->create([
            'student_id' => $student->id,
            'deleted_by_student_at' => now(),
        ]);

        Livewire::test(EditStudent::class, ['record' => $student->getKey()])
            ->assertActionHidden('delete');
    }

    // =========================================================================
    // G) Bulk delete
    // =========================================================================

    public function test_bulk_delete_removes_students_without_chat_history(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();

        Livewire::test(ListStudents::class)
            ->callTableBulkAction('delete', [$studentA, $studentB]);

        $this->assertDatabaseMissing('students', ['id' => $studentA->id]);
        $this->assertDatabaseMissing('students', ['id' => $studentB->id]);
    }

    public function test_bulk_delete_skips_students_with_chat_history(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());
        $deletable = Student::factory()->create();
        $protected = Student::factory()->create();
        ChatConversation::factory()->create(['student_id' => $protected->id]);

        Livewire::test(ListStudents::class)
            ->callTableBulkAction('delete', [$deletable, $protected]);

        // The custom BulkAction skips protected students without aborting.
        $this->assertDatabaseMissing('students', ['id' => $deletable->id]);
        $this->assertDatabaseHas('students', ['id' => $protected->id]);
    }

    // =========================================================================
    // H) Faculty relationship
    // =========================================================================

    public function test_deleting_faculty_cascades_to_its_students(): void
    {
        // students.faculty_id has cascadeOnDelete() — faculty deletion removes
        // all its students from the database (no protect constraint here).
        $faculty = Faculty::factory()->create();
        $student = Student::factory()->create(['faculty_id' => $faculty->id]);

        $faculty->delete();

        $this->assertDatabaseMissing('students', ['id' => $student->id]);
    }

    public function test_student_list_renders_when_faculty_present(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        $faculty = Faculty::factory()->create(['name' => 'Engineering Faculty']);
        Student::factory()->create(['faculty_id' => $faculty->id]);

        // faculty.name column in the table must not crash when faculty exists
        $this->get('/admin/students')->assertOk();
    }

    // =========================================================================
    // I) Password policy
    // =========================================================================

    public function test_weak_student_password_rejected_on_create(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        Livewire::test(CreateStudent::class)
            ->fillForm($this->validFormData(['password' => 'secret1234']))
            ->call('create')
            ->assertHasFormErrors(['password']);
    }

    public function test_strong_student_password_accepted_on_create(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        Livewire::test(CreateStudent::class)
            ->fillForm($this->validFormData(['password' => 'SecureStudent1!']))
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_blank_student_password_on_edit_keeps_old_password(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        $student = Student::factory()->create(['password' => 'original-password']);
        $oldHash = $student->fresh()->password;

        Livewire::test(EditStudent::class, ['record' => $student->getKey()])
            ->fillForm(['password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($oldHash, $student->fresh()->password);
    }

    public function test_weak_new_student_password_on_edit_rejected(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        $student = Student::factory()->create();

        Livewire::test(EditStudent::class, ['record' => $student->getKey()])
            ->fillForm(['password' => 'weak'])
            ->call('save')
            ->assertHasFormErrors(['password']);
    }

    public function test_strong_new_student_password_on_edit_accepted(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        $student = Student::factory()->create();

        Livewire::test(EditStudent::class, ['record' => $student->getKey()])
            ->fillForm(['password' => 'UpdatedPa55!'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    // =========================================================================
    // J) First-login / temporary-password flag
    // =========================================================================

    public function test_admin_created_student_must_change_password(): void
    {
        // Admin-created accounts use a temporary password, so the student must
        // change it on first login before normal features unlock.
        $this->loginAs(Admin::factory()->superAdmin()->create());

        Livewire::test(CreateStudent::class)
            ->fillForm($this->validFormData())
            ->call('create')
            ->assertHasNoFormErrors();

        $student = Student::where('student_id', 'GU-20240001')->firstOrFail();

        $this->assertTrue($student->password_must_be_changed);
        $this->assertNull($student->password_changed_at);
    }

    public function test_admin_password_reset_on_edit_requires_change_again(): void
    {
        // Setting a new password from the edit form is the admin "reset" path —
        // it re-arms the must-change gate.
        $this->loginAs(Admin::factory()->superAdmin()->create());

        $student = Student::factory()->create([
            'password_must_be_changed' => false,
            'password_changed_at' => now(),
        ]);

        Livewire::test(EditStudent::class, ['record' => $student->getKey()])
            ->fillForm(['password' => 'ResetPa55word!'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $student->fresh();
        $this->assertTrue($fresh->password_must_be_changed);
        $this->assertNull($fresh->password_changed_at);
    }

    public function test_admin_edit_without_password_keeps_change_flag_state(): void
    {
        // Editing other fields without touching the password must not re-arm the
        // gate for a student who has already set their own password.
        $this->loginAs(Admin::factory()->superAdmin()->create());

        $student = Student::factory()->create([
            'password_must_be_changed' => false,
            'password_changed_at' => now(),
        ]);

        Livewire::test(EditStudent::class, ['record' => $student->getKey()])
            ->fillForm(['full_name' => 'Renamed Student', 'password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($student->fresh()->password_must_be_changed);
    }

    // =========================================================================
    // K) Date of birth
    // =========================================================================

    public function test_create_saves_date_of_birth(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        Livewire::test(CreateStudent::class)
            ->fillForm($this->validFormData(['date_of_birth' => '1999-03-21']))
            ->call('create')
            ->assertHasNoFormErrors();

        $student = Student::where('student_id', 'GU-20240001')->firstOrFail();

        $this->assertSame('1999-03-21', $student->date_of_birth->format('Y-m-d'));
    }

    public function test_create_requires_date_of_birth(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        Livewire::test(CreateStudent::class)
            ->fillForm($this->validFormData(['date_of_birth' => null]))
            ->call('create')
            ->assertHasFormErrors(['date_of_birth']);
    }

    public function test_create_rejects_future_date_of_birth(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        Livewire::test(CreateStudent::class)
            ->fillForm($this->validFormData([
                'date_of_birth' => now()->addDay()->format('Y-m-d'),
            ]))
            ->call('create')
            ->assertHasFormErrors(['date_of_birth']);
    }

    public function test_edit_can_update_date_of_birth(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());
        $student = Student::factory()->create(['date_of_birth' => '1998-01-01']);

        Livewire::test(EditStudent::class, ['record' => $student->getKey()])
            ->fillForm(['date_of_birth' => '2001-12-31'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2001-12-31', $student->fresh()->date_of_birth->format('Y-m-d'));
    }

    public function test_student_list_renders_with_date_of_birth(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        Student::factory()->create(['date_of_birth' => '2000-06-15']);
        // A legacy student with a null DOB must not crash the table either.
        Student::factory()->create(['date_of_birth' => null]);

        $this->get('/admin/students')->assertOk();
    }

    // =========================================================================
    // L) Auto credits_required from selected faculty/program credit_hours
    // =========================================================================

    public function test_create_auto_sets_credits_required_from_faculty(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());
        $faculty = Faculty::factory()->create(['credit_hours' => 211]);

        Livewire::test(CreateStudent::class)
            ->fillForm($this->validFormData(['faculty_id' => $faculty->id]))
            ->call('create')
            ->assertHasNoFormErrors();

        $student = Student::where('student_id', 'GU-20240001')->firstOrFail();

        $this->assertSame(211, $student->credits_required);
    }

    public function test_create_cannot_override_credits_required_away_from_faculty(): void
    {
        // The form submits a tampered credits_required, but the server enforces
        // the selected faculty's credit_hours regardless.
        $this->loginAs(Admin::factory()->superAdmin()->create());
        $faculty = Faculty::factory()->create(['credit_hours' => 211]);

        Livewire::test(CreateStudent::class)
            ->fillForm($this->validFormData([
                'faculty_id' => $faculty->id,
                'credits_required' => 999,
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $student = Student::where('student_id', 'GU-20240001')->firstOrFail();

        $this->assertSame(211, $student->credits_required);
        $this->assertNotSame(999, $student->credits_required);
    }

    public function test_edit_changing_faculty_updates_credits_required(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        $facultyA = Faculty::factory()->create(['credit_hours' => 127]);
        $facultyB = Faculty::factory()->create(['credit_hours' => 165]);
        $student = Student::factory()->create([
            'faculty_id' => $facultyA->id,
            'credits_required' => 127,
        ]);

        Livewire::test(EditStudent::class, ['record' => $student->getKey()])
            ->fillForm(['faculty_id' => $facultyB->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(165, $student->fresh()->credits_required);
    }
}
