<?php

namespace Tests\Feature;

use App\Http\Controllers\EmployeeController;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Listado de empleados paginado y con búsqueda en el servidor (auditoría OP-07).
 */
class EmployeeListPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function seedEmployees(int $count, string $department = 'Almacén'): void
    {
        $shift = WorkShift::firstOrCreate(['name' => 'Matutino'], [
            'entry_time' => '09:00:00', 'exit_time' => '18:00:00',
            'meal_duration_minutes' => 60, 'tolerance_minutes' => 10, 'max_weekly_hours' => 48,
        ]);

        $offset = Employee::count();
        for ($i = 1; $i <= $count; $i++) {
            Employee::create([
                'hikvision_employee_no' => sprintf('%04d', $offset + $i),
                'full_name' => 'Empleado '.($offset + $i),
                'department' => $department,
                'position' => 'Operador',
                'work_shift_id' => $shift->id,
                'is_active' => true,
            ]);
        }
    }

    public function test_list_is_paginated(): void
    {
        $this->seedEmployees(3);

        $this->getJson('/employees/list?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'employees')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('employees.0.hikvision_employee_no', '0001');

        $this->getJson('/employees/list?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'employees')
            ->assertJsonPath('employees.0.hikvision_employee_no', '0003');
    }

    public function test_default_page_size_is_fifty(): void
    {
        $this->seedEmployees(EmployeeController::DEFAULT_PER_PAGE + 5);

        $this->getJson('/employees/list')
            ->assertOk()
            ->assertJsonCount(EmployeeController::DEFAULT_PER_PAGE, 'employees')
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_search_runs_on_the_server(): void
    {
        $this->seedEmployees(3, 'Almacén');
        $this->seedEmployees(2, 'Calidad');

        $this->getJson('/employees/list?q=calidad')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.q', 'calidad');

        $this->getJson('/employees/list?q=Empleado 2')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('employees.0.full_name', 'Empleado 2');
    }

    public function test_page_beyond_the_last_returns_the_last_page(): void
    {
        $this->seedEmployees(3);

        $this->getJson('/employees/list?per_page=2&page=9')
            ->assertOk()
            ->assertJsonPath('meta.page', 2)
            ->assertJsonCount(1, 'employees');
    }

    public function test_page_size_has_a_maximum(): void
    {
        $this->getJson('/employees/list?per_page='.(EmployeeController::MAX_PER_PAGE + 1))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_index_embeds_only_the_first_page(): void
    {
        $this->withoutVite();
        $this->seedEmployees(EmployeeController::DEFAULT_PER_PAGE + 5);

        $this->get('/employees')
            ->assertOk()
            ->assertSee('55 empleados')
            ->assertSee('Página 1 de 2')
            ->assertSee('Empleado 50')
            ->assertDontSee('Empleado 51');
    }

    public function test_sorting_applies_to_all_employees_before_paginating(): void
    {
        $this->seedEmployees(3);
        // El nombre "Zacarías" queda al final en orden ascendente, aunque su número de reloj sea el primero
        Employee::where('hikvision_employee_no', '0001')->update(['full_name' => 'Zacarías Ortega']);

        $this->getJson('/employees/list?per_page=2&sort=full_name&direction=asc')
            ->assertOk()
            ->assertJsonPath('employees.0.full_name', 'Empleado 2')
            ->assertJsonPath('employees.1.full_name', 'Empleado 3')
            ->assertJsonPath('meta.sort', 'full_name');

        $this->getJson('/employees/list?per_page=2&page=2&sort=full_name&direction=asc')
            ->assertOk()
            ->assertJsonPath('employees.0.full_name', 'Zacarías Ortega');

        $this->getJson('/employees/list?per_page=2&sort=full_name&direction=desc')
            ->assertOk()
            ->assertJsonPath('employees.0.full_name', 'Zacarías Ortega');
    }

    public function test_sorting_combines_with_search(): void
    {
        $this->seedEmployees(3, 'Almacén');
        $this->seedEmployees(3, 'Calidad');

        $response = $this->getJson('/employees/list?q=calidad&sort=hikvision_employee_no&direction=desc&per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('employees.0.hikvision_employee_no', '0006')
            ->assertJsonPath('employees.1.hikvision_employee_no', '0005');

        $this->assertSame(['Calidad', 'Calidad'], array_column($response->json('employees'), 'department'));
    }

    public function test_sorting_by_shift_and_status(): void
    {
        $this->seedEmployees(2);
        $night = WorkShift::create([
            'name' => 'Nocturno', 'entry_time' => '22:00:00', 'exit_time' => '06:00:00',
            'meal_duration_minutes' => 30, 'tolerance_minutes' => 10, 'max_weekly_hours' => 48,
        ]);
        Employee::where('hikvision_employee_no', '0002')->update(['work_shift_id' => $night->id, 'is_active' => false]);

        $this->getJson('/employees/list?sort=shift_name&direction=desc')
            ->assertOk()
            ->assertJsonPath('employees.0.shift_name', 'Nocturno');

        $this->getJson('/employees/list?sort=status_label&direction=asc')
            ->assertOk()
            ->assertJsonPath('employees.0.status_label', 'Activo')
            ->assertJsonPath('employees.1.status_label', 'Inactivo');
    }

    public function test_sorting_rejects_unknown_columns(): void
    {
        $this->getJson('/employees/list?sort=password&direction=asc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort');

        $this->getJson('/employees/list?sort=full_name&direction=sideways')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('direction');
    }
}
