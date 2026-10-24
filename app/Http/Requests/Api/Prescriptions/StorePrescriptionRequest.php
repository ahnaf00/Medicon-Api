<?php

namespace App\Http\Requests\Api\Prescriptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePrescriptionRequest extends FormRequest
{
    // One prescription per visit (audit G1).
    public const ONE_PER_VISIT = 'This visit already has a prescription. Open it from the patient\'s prescriptions.';

    /** Times of day a dose can be scheduled; the app writes these keys too. */
    public const SLOTS = ['morning', 'noon', 'night'];

    /** How the medicine is taken (audit G1 / CLIN-005). */
    public const ROUTES = ['oral', 'sublingual', 'topical', 'inhaled', 'nasal', 'eye', 'ear', 'injection', 'rectal', 'vaginal', 'other'];

    public function authorize(): bool
    {
        return $this->user() && $this->user()->hasRole('doctor');
    }

    public function messages(): array
    {
        return [
            'appointment_id.unique' => self::ONE_PER_VISIT,
        ];
    }

    public function rules(): array
    {
        $clinicToday = now(config('app.clinic_timezone', 'Asia/Dhaka'))->toDateString();

        return [
            'appointment_id'                => ['nullable', 'exists:appointments,id', Rule::unique('prescriptions', 'appointment_id')],
            'patient_user_id'               => ['required', 'exists:users,id'],
            'diagnosis_summary'             => ['required', 'string', 'max:2000'],
            'medicines'                     => ['required', 'array', 'min:1'],
            'medicines.*.medicine_name'     => ['required', 'string', 'max:255'],
            'medicines.*.dosage'            => ['required', 'string', 'max:100'],
            'medicines.*.dosage_schedule'   => ['nullable', 'array'],
            // How many units at each time of day, e.g. {morning: 2, night: 1} → printed 2+0+1.
            'medicines.*.dose_quantities'   => ['nullable', 'array:'.implode(',', self::SLOTS)],
            'medicines.*.dose_quantities.*' => ['nullable', 'numeric', 'min:0.25', 'max:10'],
            'medicines.*.route'             => ['nullable', Rule::in(self::ROUTES)],
            'medicines.*.instructions'      => ['nullable', 'string', 'max:255'],
            'medicines.*.duration_days'     => ['required', 'integer', 'min:1'],
            'tests'                         => ['nullable', 'array'],
            'tests.*.name'                  => ['required', 'string', 'max:255'],
            'tests.*.instructions'          => ['nullable', 'string', 'max:255'],
            // A calendar date: "today" is the clinic's (Dhaka) today, not UTC's.
            'follow_up_date'                => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$clinicToday],
            'advice'                        => ['nullable', 'string', 'max:2000'],
            // The doctor saw the allergy warning and prescribes anyway.
            'allergy_acknowledged'          => ['nullable', 'boolean'],
            // Reissue after cancelling: the cancelled prescription this one replaces.
            'replaces_prescription_id'      => ['nullable', 'integer', 'exists:prescriptions,id'],
        ];
    }

    /** A quantity is only meaningful for a time of day that is actually scheduled. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ((array) $this->input('medicines', []) as $i => $medicine) {
                    $quantities = array_filter((array) ($medicine['dose_quantities'] ?? []), fn ($q) => $q !== null);
                    $schedule = array_filter((array) ($medicine['dosage_schedule'] ?? []));
                    foreach (array_keys($quantities) as $slot) {
                        if (! array_key_exists($slot, $schedule)) {
                            $validator->errors()->add(
                                "medicines.{$i}.dose_quantities.{$slot}",
                                "Medicine #".($i + 1).": a {$slot} amount was given but no {$slot} time is scheduled.",
                            );
                        }
                    }
                }
            },
        ];
    }
}
