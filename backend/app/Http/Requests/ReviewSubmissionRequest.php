<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'score'    => ['required', 'integer', 'min:0', 'max:100'],
            'feedback' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }
}