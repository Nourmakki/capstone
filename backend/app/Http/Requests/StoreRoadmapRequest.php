<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoadmapRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware already restricts this to Admin.
        return true;
    }

    public function rules(): array
    {
        $roadmapId = $this->route('roadmap')?->id;

        return [
            'title' => [
                $this->isMethod('POST') ? 'required' : 'sometimes',
                'string',
                'max:255',
                'unique:roadmaps,title' . ($roadmapId ? ",{$roadmapId}" : ''),
            ],
            'description' => [
                $this->isMethod('POST') ? 'required' : 'sometimes',
                'string',
            ],
        ];
    }
}