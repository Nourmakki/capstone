<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTodoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // PATCH/PUT may send only the fields being changed.
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title'         => [$required, 'string', 'max:255'],
            'description'   => ['nullable', 'string', 'max:2000'],
            'due_date'      => ['nullable', 'date'],
            'priority'      => ['nullable', 'in:low,medium,high'],
            'reminder_time' => ['nullable', 'date_format:H:i'],
            'is_completed'  => ['nullable', 'boolean'],
        ];
    }
}