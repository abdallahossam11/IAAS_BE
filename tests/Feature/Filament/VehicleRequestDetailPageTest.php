<?php

namespace Tests\Feature\Filament;

use App\Models\Admin;
use App\Models\VehicleRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for the VehicleRequest detail page (ViewVehicleRequest).
 *
 * Root cause of the original crash:
 *   ->default('—') on nullable dateTime/date entries passed the string '—'
 *   through Carbon::parse(), throwing InvalidFormatException on pending and
 *   rejected records where approved_at / semester_start_date / semester_end_date
 *   are null.
 *
 * Fix applied:
 *   Replaced ->default('—') with ->placeholder('—') on those three entries so
 *   that null values are never passed through the Carbon formatter.
 */
class VehicleRequestDetailPageTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function actingAsVehicleAdmin(): Admin
    {
        $admin = Admin::factory()->vehicleAdmin()->create();
        $this->actingAs($admin, 'web');

        return $admin;
    }

    private function viewUrl(VehicleRequest $request): string
    {
        // VehicleRequestResource::getUrl() requires a Filament panel context that
        // is not set during plain HTTP tests. Use the known panel path directly;
        // the 'admin' panel is registered with path('admin') in AdminPanelProvider
        // and the resource resolves records by primary key.
        return '/admin/vehicle-requests/'.$request->getKey();
    }

    // -------------------------------------------------------------------------
    // Happy-path: page renders for each status
    // -------------------------------------------------------------------------

    /**
     * Pending request — all three nullable date fields are null.
     * This was the primary crash scenario before the fix.
     */
    public function test_vehicle_admin_can_open_pending_request_with_null_dates(): void
    {
        $this->actingAsVehicleAdmin();

        $request = VehicleRequest::factory()->pending()->create();

        $this->get($this->viewUrl($request))->assertOk();
    }

    /**
     * Rejected request — date fields null, rejection_reason set.
     * Also crashed before the fix because the same null date fields were present.
     */
    public function test_vehicle_admin_can_open_rejected_request_with_null_dates(): void
    {
        $admin = $this->actingAsVehicleAdmin();

        $request = VehicleRequest::factory()->rejected($admin)->create();

        $this->get($this->viewUrl($request))->assertOk();
    }

    /**
     * Approved request — approved_at, semester_start_date, semester_end_date
     * all have real Carbon values; this path never crashed but must keep working.
     */
    public function test_vehicle_admin_can_open_approved_request_with_real_dates(): void
    {
        $admin = $this->actingAsVehicleAdmin();

        $request = VehicleRequest::factory()->approved($admin)->create();

        $this->get($this->viewUrl($request))->assertOk();
    }

    // -------------------------------------------------------------------------
    // Authorization: non-vehicle-admin roles must be denied
    // -------------------------------------------------------------------------

    public function test_support_admin_cannot_access_vehicle_request_detail(): void
    {
        $admin = Admin::factory()->supportAdmin()->create();
        $this->actingAs($admin, 'web');

        $request = VehicleRequest::factory()->pending()->create();

        $this->get($this->viewUrl($request))->assertForbidden();
    }

    public function test_academic_admin_cannot_access_vehicle_request_detail(): void
    {
        $admin = Admin::factory()->academicAdmin()->create();
        $this->actingAs($admin, 'web');

        $request = VehicleRequest::factory()->pending()->create();

        $this->get($this->viewUrl($request))->assertForbidden();
    }
}
