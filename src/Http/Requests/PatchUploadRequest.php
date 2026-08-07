<?php

namespace Sopamo\LaravelFilepond\Http\Requests;

class PatchUploadRequest extends FilepondRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'patch' => ['required', 'string'],
            '_upload_offset' => ['required', 'integer', 'min:0'],
            '_upload_length' => [
                'required',
                'integer',
                'min:0',
                'max:'.(int) config('filepond.maximum_upload_size'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return array_merge(parent::validationData(), [
            '_upload_offset' => $this->header('Upload-Offset'),
            '_upload_length' => $this->header('Upload-Length'),
        ]);
    }

    public function serverId(): string
    {
        return $this->validated('patch');
    }

    public function uploadOffset(): int
    {
        return (int) $this->validated('_upload_offset');
    }

    public function uploadLength(): int
    {
        return (int) $this->validated('_upload_length');
    }

    protected function validationStatus(): int
    {
        return 400;
    }
}
