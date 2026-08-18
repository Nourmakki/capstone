<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'github_url' => ['nullable', 'url', 'max:255'],
            'file'       => ['nullable', 'file', 'mimes:zip', 'max:10240'],
            'notes'      => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('github_url') && ! $this->hasFile('file')) {
                $validator->errors()->add(
                    'github_url',
                    'Provide a GitHub link or upload a ZIP file.'
                );
            }
        });
    }
}