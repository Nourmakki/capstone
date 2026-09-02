<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 110, not 100.
     *
     * Her assessment scores a base out of 100 plus up to 10 bonus, so the
     * scale a professor is reviewing against tops out at 110 - confirmed with
     * Nour on 2026-09-02. The review screen shows them the AI's score, and
     * capping this at 100 meant a professor who agreed with a top mark could
     * not enter it: the form refused the number it had just displayed.
     */
    public function rules(): array
    {
        return [
            'score'    => ['required', 'integer', 'min:0', 'max:110'],
            'feedback' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }
}