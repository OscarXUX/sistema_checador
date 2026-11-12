<?php

namespace App\Http\Requests;

class UpdateEmployeeRequest extends EmployeeFormRequest
{
    protected function defaultActiveValue(): bool
    {
        return false;
    }

    protected function ignoreEmployeeId(): mixed
    {
        return $this->route('employee')?->id ?? $this->route('employee');
    }
}
