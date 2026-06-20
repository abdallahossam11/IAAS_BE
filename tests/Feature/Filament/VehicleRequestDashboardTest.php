<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\VehicleRequestResource\Pages\ListVehicleRequests;
use App\Models\Admin;
use App\Models\VehicleRequest;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression tests for the VehicleRequest Filament dashboard.
 *
 * Covers:
 *   - Role access to the list and view pages.
 *   - Approve action: database writes and guard behaviour.
 *   - Reject action: database writes and guard behaviour.
 *   - Action visibility based on request status.
 *   - Approve blocked when student already has an active permit.
 *
 * Policy:
 *   super_admin  → can access
 *   vehicle_admin → can access
 *   support_admin → denied
 *   academic_admin → denied
 *
 * Note on callTableAction:
 *   Filament's callTableAction() asserts the action is VISIBLE before calling it.
 *   We test the server-side guard (approve/reject on non-pending records) via
 *   assertTableActionHidden, which is the canonical UI-level protection.
 */
class VehicleRequestDashboardTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Boot the Filament panel so Livewire::test() has a panel context
    // -------------------------------------------------------------------------

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

    private function viewUrl(VehicleRequest $request): string
    {
        return '/admin/vehicle-requests/'.$request->getKey();
    }

    // =========================================================================
    // A) Role access — list page
    // =========================================================================

    public function test_super_admin_can_access_vehicle_request_list(): void
    {
        $this->loginAs(Admin::factory()->superAdmin()->create());

        $this->get('/admin/vehicle-requests')->assertOk();
    }

    public function test_vehicle_admin_can_access_vehicle_request_list(): void
    {
        $this->loginAs(Admin::factory()->vehicleAdmin()->create());

        $this->get('/admin/vehicle-requests')->assertOk();
    }

    public function test_support_admin_cannot_access_vehicle_request_list(): void
    {
        $this->loginAs(Admin::factory()->supportAdmin()->create());

        $this->get('/admin/vehicle-requests')->assertForbidden();
    }

    public function test_academic_admin_cannot_access_vehicle_request_list(): void
    {
        $this->loginAs(Admin::factory()->academicAdmin()->create());

        $this->get('/admin/vehicle-requests')->assertForbidden();
    }

    // =========================================================================
    // B) Role access — view/detail page
    //    (These overlap with VehicleRequestDetailPageTest but confirm list+view)
    // =========================================================================

    public function test_super_admin_can_access_vehicle_request_detail(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $this->loginAs($admin);

        $this->get($this->viewUrl(VehicleRequest::factory()->pending()->create()))
            ->assertOk();
    }

    public function test_support_admin_cannot_access_vehicle_request_detail(): void
    {
        $this->loginAs(Admin::factory()->supportAdmin()->create());

        $this->get($this->viewUrl(VehicleRequest::factory()->pending()->create()))
            ->assertForbidden();
    }

    // =========================================================================
    // C) Approve action
    // =========================================================================

    public function test_vehicle_admin_can_approve_pending_request(): void
    {
        $admin = Admin::factory()->vehicleAdmin()->create();
        $this->loginAs($admin);

        $request = VehicleRequest::factory()->pending()->create();

        Livewire::test(ListVehicleRequests::class)
            ->callTableAction('approve', $request, data: [
                'semester_start_date' => '2026-09-01',
                'semester_end_date' => '2027-01-31',
            ])
            ->assertHasNoTableActionErrors();

        $fresh = $request->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($admin->id, $fresh->admin_id);
        $this->assertNotNull($fresh->approved_at);
        $this->assertSame('2026-09-01', $fresh->semester_start_date->toDateString());
        $this->assertSame('2027-01-31', $fresh->semester_end_date->toDateString());
        $this->assertNull($fresh->rejection_reason);
    }

    public function test_super_admin_can_approve_pending_request(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $this->loginAs($admin);

        $request = VehicleRequest::factory()->pending()->create();

        Livewire::test(ListVehicleRequests::class)
            ->callTableAction('approve', $request, data: [
                'semester_start_date' => '2026-09-01',
                'semester_end_date' => '2027-01-31',
            ]);

        $this->assertSame('approved', $request->fresh()->status);
    }

    // =========================================================================
    // D) Reject action
    // =========================================================================

    public function test_vehicle_admin_can_reject_pending_request(): void
    {
        $admin = Admin::factory()->vehicleAdmin()->create();
        $this->loginAs($admin);

        $request = VehicleRequest::factory()->pending()->create();

        Livewire::test(ListVehicleRequests::class)
            ->callTableAction('reject', $request, data: [
                'rejection_reason' => 'Missing documentation',
            ])
            ->assertHasNoTableActionErrors();

        $fresh = $request->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame($admin->id, $fresh->admin_id);
        $this->assertSame('Missing documentation', $fresh->rejection_reason);
        $this->assertNull($fresh->approved_at);
        $this->assertNull($fresh->semester_start_date);
        $this->assertNull($fresh->semester_end_date);
    }

    // =========================================================================
    // E) Action visibility based on record status
    //    The ->visible() closure hides both actions for non-pending records.
    // =========================================================================

    public function test_both_actions_are_visible_for_pending_request(): void
    {
        $admin = Admin::factory()->vehicleAdmin()->create();
        $this->loginAs($admin);

        $request = VehicleRequest::factory()->pending()->create();

        Livewire::test(ListVehicleRequests::class)
            ->assertTableActionVisible('approve', $request)
            ->assertTableActionVisible('reject', $request);
    }

    public function test_both_actions_are_hidden_for_approved_request(): void
    {
        $admin = Admin::factory()->vehicleAdmin()->create();
        $this->loginAs($admin);

        $request = VehicleRequest::factory()->approved($admin)->create();

        Livewire::test(ListVehicleRequests::class)
            ->assertTableActionHidden('approve', $request)
            ->assertTableActionHidden('reject', $request);
    }

    public function test_both_actions_are_hidden_for_rejected_request(): void
    {
        $admin = Admin::factory()->vehicleAdmin()->create();
        $this->loginAs($admin);

        $request = VehicleRequest::factory()->rejected($admin)->create();

        Livewire::test(ListVehicleRequests::class)
            ->assertTableActionHidden('approve', $request)
            ->assertTableActionHidden('reject', $request);
    }

    // =========================================================================
    // F) Approve is blocked when the student already has an active permit
    //    (the action guard sends a Notification and returns without saving)
    // =========================================================================

    public function test_approve_is_blocked_when_student_has_active_permit(): void
    {
        $admin = Admin::factory()->vehicleAdmin()->create();
        $this->loginAs($admin);

        $pending = VehicleRequest::factory()->pending()->create();

        // Same student already has an active approved permit
        VehicleRequest::factory()->approved($admin)->create([
            'student_id' => $pending->student_id,
            'semester_end_date' => Carbon::today()->addMonths(3),
        ]);

        Livewire::test(ListVehicleRequests::class)
            ->callTableAction('approve', $pending, data: [
                'semester_start_date' => '2027-09-01',
                'semester_end_date' => '2028-01-31',
            ]);

        // Status unchanged — the active-permit guard fired
        $this->assertSame('pending', $pending->fresh()->status);
    }

    // =========================================================================
    // G) Approve requires both semester dates
    // =========================================================================

    public function test_approve_requires_semester_start_date(): void
    {
        $admin = Admin::factory()->vehicleAdmin()->create();
        $this->loginAs($admin);

        $request = VehicleRequest::factory()->pending()->create();

        Livewire::test(ListVehicleRequests::class)
            ->mountTableAction('approve', $request)
            ->setTableActionData(['semester_end_date' => '2027-01-31'])
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['semester_start_date']);
    }

    public function test_approve_requires_semester_end_date(): void
    {
        $admin = Admin::factory()->vehicleAdmin()->create();
        $this->loginAs($admin);

        $request = VehicleRequest::factory()->pending()->create();

        Livewire::test(ListVehicleRequests::class)
            ->mountTableAction('approve', $request)
            ->setTableActionData(['semester_start_date' => '2026-09-01'])
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['semester_end_date']);
    }

    // =========================================================================
    // H) Reject requires a rejection reason
    // =========================================================================

    public function test_reject_requires_rejection_reason(): void
    {
        $admin = Admin::factory()->vehicleAdmin()->create();
        $this->loginAs($admin);

        $request = VehicleRequest::factory()->pending()->create();

        Livewire::test(ListVehicleRequests::class)
            ->mountTableAction('reject', $request)
            ->setTableActionData([])
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['rejection_reason']);
    }
}
