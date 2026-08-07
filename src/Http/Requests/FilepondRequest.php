<?php

namespace Sopamo\LaravelFilepond\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class FilepondRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): never
    {
        $status = $this->validationStatus();

        if ($this->expectsJson()) {
            throw new HttpResponseException(response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors()->toArray(),
            ], $status));
        }

        throw new HttpResponseException(response(
            $validator->errors()->first(),
            $status,
            ['Content-Type' => 'text/plain'],
        ));
    }

    protected function validationStatus(): int
    {
        return 422;
    }
}
