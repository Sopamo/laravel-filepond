<?php

namespace Nocs\LaravelFilepond\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Arr;

class Upload implements Rule
{
    
    protected $min;

    protected $max;

    protected $limitFileSize;

    protected $limitToMimetypes;

    protected $fail;

    protected $forbiddenMimetype;

    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct($rules = [])
    {
        
        $rules = Arr::extend([
            'min'                => 1,
            'max'                => null,
            'limitFileSize'      => null,
            'limitToMimetypes'   => [],
        ], $rules ?? []);
        
        $this->min = $rules['min'];

        $this->max = $rules['max'];

        $this->limitFileSize = is_numeric($rules['limitFileSize']) ? $rules['limitFileSize'] : null;

        $this->limitToMimetypes = is_array($rules['limitToMimetypes']) ? $rules['limitToMimetypes'] : null;

        $this->fail = null;

    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        try {
            if (is_string($value)) {
                $value = json_decode($value, true);
            } elseif (is_object($value)) {
                $value = json_decode(json_encode($value), true);
            }
        } catch (\Exception $e) {
            $value = null;
        }

        if (!isset($value['c']) || !is_array($value['c']) ||
            !isset($value['r']) || !is_array($value['r']) ||
            !isset($value['d']) || !is_array($value['d'])) {
            return false;
        }

        $u = array_merge(array_diff($value['r'], $value['d']), $value['c']);
        
        if ($this->min && (count($u) < $this->min)) {
            $this->fail = 'min';
            return false;
        }

        if ($this->max && (count($u) > $this->max)) {
            $this->fail = 'max';
            return false;
        }
        
        if (count($u) && ($this->limitFileSize || $this->limitToMimetypes)) {
            $unmasked = filepond()->unmask($value, []);
            foreach($unmasked['c'] as $fileInfo) {
                if ($this->limitFileSize && $fileInfo->size > $this->limitFileSize) {
                    $this->fail = 'limitFileSize';
                    return false;
                }

                if ($this->limitToMimetypes && ! in_array($fileInfo->mimetype, $this->limitToMimetypes)) {
                    $this->fail = 'limitToMimetypes';
                    $this->forbiddenMimetype = $fileInfo->mimetype;
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {

        switch ($this->fail) {

            case 'min':
                return trans('validation.upload-min');

            case 'max':
                return trans('validation.upload-max');

            case 'limitFileSize':
                return trans('validation.upload-limit-file-size', ['sizelimit' => round($this->limitFileSize / 1024 / 1024, 1) .'Mb']);

            case 'limitToMimetypes':
                return trans('validation.upload-limit-mimetypes', ['forbiddenmimetype' => $this->forbiddenMimetype]);

            default:
                return trans('validation.upload');

        }

    }
}
