<?php

namespace Sopamo\LaravelFilepond\Http\Requests;

use Closure;
use Illuminate\Http\UploadedFile;

class ProcessUploadRequest extends FilepondRequest
{
    /** @return array<string, array<int, string|Closure>> */
    public function rules(): array
    {
        $inputName = (string) config('filepond.input_name', 'file');
        $uploadedInput = $this->file($inputName);
        $isChunkInitializationMetadata = $this->header('Upload-Length') !== null
            && $uploadedInput === null
            && is_string($this->input($inputName));

        $fileRules = ['nullable', 'file'];
        if (is_array($uploadedInput)) {
            $fileRules = ['nullable', 'array', 'min:1'];
        }
        $fileRules[] = $this->maximumFileSizeRule();

        $rules = [
            $inputName => $isChunkInitializationMetadata ? ['exclude'] : $fileRules,
            '_upload_name' => ['nullable', 'string', 'max:255'],
            '_upload_length' => [
                'nullable',
                'integer',
                'min:0',
                'max:'.(int) config('filepond.maximum_upload_size'),
            ],
        ];

        if (is_array($uploadedInput)) {
            $rules[$inputName.'.*'] = ['file'];
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return array_merge(parent::validationData(), [
            '_upload_name' => $this->header('Upload-Name'),
            '_upload_length' => $this->header('Upload-Length'),
        ]);
    }

    public function uploadedFile(): ?UploadedFile
    {
        $file = $this->file((string) config('filepond.input_name', 'file'));
        if (is_array($file)) {
            $file = reset($file);
        }

        return $file instanceof UploadedFile ? $file : null;
    }

    private function maximumFileSizeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_array($value)) {
                $value = reset($value);
            }

            if (!$value instanceof UploadedFile) {
                return;
            }

            $fileSize = $value->getSize();
            if (is_int($fileSize) && $fileSize > (int) config('filepond.maximum_upload_size')) {
                $fail('The uploaded file exceeds the configured maximum upload size.');
            }
        };
    }

    public function uploadName(): ?string
    {
        $name = $this->validated('_upload_name');

        return is_string($name) ? $name : null;
    }

    public function uploadLength(): ?int
    {
        $length = $this->validated('_upload_length');

        return is_string($length) ? (int) $length : null;
    }
}
