<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class OrderSave extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (!$this->exists('restart_cycle')) {
            return;
        }

        $this->merge([
            'restart_cycle' => filter_var(
                $this->input('restart_cycle'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            ) ?? false,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'plan_id' => 'required',
            'period' => 'required|in:month_price,quarter_price,half_year_price,year_price,two_year_price,three_year_price,onetime_price,reset_price',
            'restart_cycle' => 'sometimes|boolean',
        ];
    }

    public function wantsRestartCycle(): bool
    {
        return filter_var($this->input('restart_cycle', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function messages()
    {
        return [
            'plan_id.required' => __('Plan ID cannot be empty'),
            'period.required' => __('Plan period cannot be empty'),
            'period.in' => __('Wrong plan period')
        ];
    }
}
