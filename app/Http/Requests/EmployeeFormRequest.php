<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class EmployeeFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'hikvision_employee_no' => trim((string) $this->input('hikvision_employee_no')),
            'rfc' => $this->filled('rfc') ? strtoupper(trim((string) $this->input('rfc'))) : null,
            'curp' => $this->filled('curp') ? strtoupper(trim((string) $this->input('curp'))) : null,
            'nss' => $this->filled('nss') ? str_replace([' ', '-'], '', trim((string) $this->input('nss'))) : null,
            'is_active' => $this->boolean('is_active', $this->defaultActiveValue()),
        ]);
    }

    abstract protected function defaultActiveValue(): bool;

    protected function ignoreEmployeeId(): mixed
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $uniqueRule = Rule::unique('employees', 'hikvision_employee_no');
        $ignoreId = $this->ignoreEmployeeId();
        if ($ignoreId !== null) {
            $uniqueRule->ignore($ignoreId);
        }

        return [
            'hikvision_employee_no' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/', $uniqueRule],
            'full_name' => ['required', 'string', 'max:150'],
            'rfc' => ['nullable', 'string', 'min:12', 'max:13', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/'],
            'curp' => ['nullable', 'string', 'size:18', 'regex:/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/'],
            'nss' => ['nullable', 'string', 'size:11', 'regex:/^\d{11}$/'],
            'department' => ['required', 'string', 'max:100'],
            'position' => ['required', 'string', 'max:100'],
            'work_shift_id' => ['required', 'integer', 'exists:work_shifts,id'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hikvision_employee_no.required' => 'El número de empleado Hikvision es obligatorio.',
            'hikvision_employee_no.unique' => 'Ya existe un empleado con ese número en el reloj.',
            'hikvision_employee_no.regex' => 'El número de empleado solo debe contener letras, números, guiones o guion bajo.',
            'full_name.required' => 'El nombre completo del empleado es obligatorio.',
            'department.required' => 'El departamento es obligatorio.',
            'position.required' => 'El puesto es obligatorio.',
            'work_shift_id.required' => 'Debe seleccionar un turno fiscal.',
            'rfc.min' => 'El RFC debe tener al menos 12 caracteres.',
            'rfc.max' => 'El RFC no puede exceder 13 caracteres.',
            'rfc.regex' => 'El RFC no cumple con la estructura válida del SAT (12 o 13 caracteres).',
            'curp.size' => 'La CURP debe tener exactamente 18 caracteres.',
            'curp.regex' => 'La CURP debe tener 18 caracteres con formato oficial RENAPO.',
            'nss.size' => 'El NSS debe tener exactamente 11 dígitos.',
            'nss.regex' => 'El NSS debe contener exactamente 11 dígitos numéricos.',
        ];
    }
}

