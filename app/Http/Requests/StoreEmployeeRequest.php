<?php

namespace App\Http\Requests;

class StoreEmployeeRequest extends EmployeeFormRequest
{
    protected function defaultActiveValue(): bool
    {
        return true;
    }
}
