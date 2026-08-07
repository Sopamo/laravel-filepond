<?php

namespace Sopamo\LaravelFilepond\Http\Requests;

class RevertUploadRequest extends FilepondRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['_server_id' => ['required', 'string']];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return ['_server_id' => $this->getContent()];
    }

    public function serverId(): string
    {
        return $this->validated('_server_id');
    }
}
