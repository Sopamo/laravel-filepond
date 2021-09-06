<?php

namespace Nocs\LaravelFilepond\Rules;

use Illuminate\Contracts\Validation\Rule;

class Upload implements Rule
{
    
    protected $min;

    protected $max;

    protected $fail;

    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct($min = 1, $max = null)
    {

        $this->min = $min;

        $this->max = $max;

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

            default:
                return trans('validation.upload');

        }

    }
}
