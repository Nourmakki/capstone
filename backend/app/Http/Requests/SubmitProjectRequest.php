<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitProjectRequest extends FormRequest
{
    /**
     * What the AI grader can actually read, from
     * AI-System/learning_workflow/tools/submission_processor.py
     * ALLOWED_SUBMISSION_EXTENSIONS. Accepting anything else would take a
     * submission the grader then refuses.
     *
     * A zip is still the usual answer for a multi-file project; the rest are
     * here so a single notebook or script does not have to be wrapped in one.
     */
    public const ACCEPTED = [
        'zip', 'sql', 'py', 'ipynb', 'csv', 'png', 'jpg', 'jpeg', 'pdf', 'txt', 'md',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Both are required: the repository is what a reviewer reads, and the
        // file is the fixed snapshot of what was actually handed in.
        return [
            'github_url' => ['required', 'url', 'max:255'],
            'file'       => [
                'required',
                'file',
                // Both checks are needed. mimes reads the file's contents, so
                // a renamed archive is caught - but every text format here
                // reports as text/plain, which would let a .js through on the
                // strength of .txt. extensions pins the name as well, and the
                // grader looks at the extension.
                'extensions:' . implode(',', self::ACCEPTED),
                'mimes:' . implode(',', self::ACCEPTED),
                $this->contentMatchesExtension(),
                'max:10240',
            ],
            'notes'      => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * `extensions` checks the name and `mimes` checks the contents, but
     * nothing makes the two agree: a Python file renamed to .zip satisfies
     * both, because its contents pass as .txt. That only matters for the
     * formats something later has to open - the grader unzips a .zip and
     * decodes an image - so those are pinned here.
     *
     * The text formats are deliberately left alone. A .py holding notes is
     * odd but harmless, and everything reads as text/plain anyway.
     */
    private function contentMatchesExtension(): callable
    {
        return function (string $attribute, $value, callable $fail): void {
            $mustMatch = [
                'zip'  => ['application/zip', 'application/x-zip-compressed'],
                'pdf'  => ['application/pdf'],
                'png'  => ['image/png'],
                'jpg'  => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
            ];

            if (! $value instanceof \Illuminate\Http\UploadedFile) {
                return;
            }

            $extension = strtolower($value->getClientOriginalExtension());

            if (! isset($mustMatch[$extension])) {
                return;
            }

            if (! in_array($value->getMimeType(), $mustMatch[$extension], true)) {
                $fail("That file is named .{$extension}, but its contents are not a "
                    . strtoupper($extension) . '.');
            }
        };
    }

    public function messages(): array
    {
        return [
            'github_url.required' => 'Add the link to your GitHub repository.',
            'file.required'       => 'Attach your work as a file.',
            'file.mimes'          => 'That file type is not accepted. Use a ZIP, or one of: '
                                     . implode(', ', self::ACCEPTED) . '.',
            'file.extensions'     => 'That file type is not accepted. Use a ZIP, or one of: '
                                     . implode(', ', self::ACCEPTED) . '.',
            'file.max'            => 'That file is larger than the server accepts.',
            // Laravel's default here is "The file failed to upload", which is
            // what PHP reports when the file exceeds upload_max_filesize.
            'file.uploaded'       => 'That file is larger than the server accepts. '
                                     . 'Check php.ini upload_max_filesize and post_max_size.',
        ];
    }
}
