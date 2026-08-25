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
        // Both are required: the repository is what a reviewer reads, and the
        // archive is the fixed snapshot of what was actually handed in.
        return [
            'github_url' => ['required', 'url', 'max:255'],
            'file'       => ['required', 'file', 'mimes:zip', 'max:10240'],
            'notes'      => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'github_url.required' => 'Add the link to your GitHub repository.',
            'file.required'       => 'Attach your project as a ZIP file.',
            'file.mimes'          => 'The project file must be a .zip archive.',
            'file.max'            => 'The project ZIP is larger than the server accepts.',
            // Laravel's default here is "The file failed to upload", which is
            // what PHP reports when the file exceeds upload_max_filesize.
            'file.uploaded'       => 'The ZIP is larger than the server accepts. '
                                     . 'Check php.ini upload_max_filesize and post_max_size.',
        ];
    }
}