<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\DoctorAvailability;
use App\Models\DoctorScheduleException;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Builds a doctor's bookable slots from their weekly availability, daily
 * exceptions and existing appointments.
 *
 * Shared by the public slots endpoint, booking and the symptom-search ranking,
 * so the "next available" time patients see always matches what they can book.
 *
 * Working hours and exceptions are wall-clock times in the clinic timezone
 * (Asia/Dhaka). Appointments are stored in UTC. Every slot carries its Dhaka
 * label (`time`) and its UTC instant (`datetime`, ISO-8601 with offset).
 */
class DoctorSlotService
{
    public const SLOT_DURATION = 30; // minutes

    /** Appointment statuses that occupy a slot. */
    private const BOOKED_STATUSES = ['scheduled', 'completed', 'in_progress'];

    // Used when a doctor has no availability configured for a weekday.
    private const DEFAULT_START = '09:00:00';
    private const DEFAULT_END = '17:00:00';
    private const DEFAULT_OFF_DAYS = [5, 6]; // Fri, Sat

    public static function timezone(): string
    {
        return config('app.clinic_timezone', 'Asia/Dhaka');
    }

    /** Today's date in the clinic timezone, at midnight. */
    public static function today(): Carbon
    {
        return now(self::timezone())->startOfDay();
    }

    /**
     * All slots for one doctor on one clinic-timezone calendar date (Y-m-d).
     *
     * @return array<int, array{time: string, datetime: string, available: bool}>
     */
    public function slotsOn(int $doctorId, string $date): array
    {
        $day = Carbon::parse($date, self::timezone())->startOfDay();

        $availability = DoctorAvailability::where('doctor_user_id', $doctorId)
            ->where('day_of_week', $day->dayOfWeek)
            ->first();

        $exceptions = DoctorScheduleException::where('doctor_user_id', $doctorId)
            ->whereDate('date', $day->toDateString())
            ->get();

        $bookedTimes = Appointment::where('doctor_user_id', $doctorId)
            ->whereBetween('appointment_datetime', $this->utcRange($day, $day))
            ->whereIn('status', self::BOOKED_STATUSES)
            ->pluck('appointment_datetime')
            ->map(fn ($dt) => $this->localTime($dt))
            ->all();

        return $this->buildSlots($day, $availability, $exceptions, $bookedTimes);
    }

    /**
     * Whether `$start` is exactly the start of a free slot for this doctor.
     */
    public function isBookable(int $doctorId, CarbonInterface $start): bool
    {
        $local = $start->copy()->setTimezone(self::timezone());
        if ($local->second !== 0) {
            return false;
        }

        foreach ($this->slotsOn($doctorId, $local->toDateString()) as $slot) {
            if ($slot['time'] === $local->format('H:i')) {
                return $slot['available'];
            }
        }

        return false;
    }

    /**
     * The first free, not-yet-passed slot for each doctor within the next `$days` days.
     * Loads availability, exceptions and appointments for all doctors in three queries.
     *
     * @param  array<int, int>  $doctorIds
     * @return array<int, Carbon|null> keyed by doctor user id, in UTC
     */
    public function nextAvailable(array $doctorIds, int $days = 14): array
    {
        if ($doctorIds === []) {
            return [];
        }

        $now = now();
        $from = self::today();
        $to = $from->copy()->addDays($days - 1);

        $availabilities = DoctorAvailability::whereIn('doctor_user_id', $doctorIds)
            ->get()
            ->groupBy('doctor_user_id');

        $exceptions = DoctorScheduleException::whereIn('doctor_user_id', $doctorIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy(fn ($ex) => $ex->doctor_user_id.'|'.Carbon::parse($ex->date)->toDateString());

        $booked = Appointment::whereIn('doctor_user_id', $doctorIds)
            ->whereBetween('appointment_datetime', $this->utcRange($from, $to))
            ->whereIn('status', self::BOOKED_STATUSES)
            ->get(['doctor_user_id', 'appointment_datetime'])
            ->groupBy(fn ($a) => $a->doctor_user_id.'|'.$a->appointment_datetime->copy()->setTimezone(self::timezone())->toDateString())
            ->map(fn ($group) => $group->map(fn ($a) => $this->localTime($a->appointment_datetime))->all());

        $next = [];
        foreach ($doctorIds as $doctorId) {
            $next[$doctorId] = null;

            for ($i = 0; $i < $days; $i++) {
                $date = $from->copy()->addDays($i);
                $key = $doctorId.'|'.$date->toDateString();

                $slots = $this->buildSlots(
                    $date,
                    $availabilities->get($doctorId)?->firstWhere('day_of_week', $date->dayOfWeek),
                    $exceptions->get($key, collect()),
                    $booked->get($key, []),
                );

                foreach ($slots as $slot) {
                    $start = Carbon::parse($slot['datetime'])->utc();
                    if ($slot['available'] && $start->greaterThan($now)) {
                        $next[$doctorId] = $start;
                        break 2;
                    }
                }
            }
        }

        return $next;
    }

    /**
     * UTC bounds covering whole clinic-timezone days, for querying stored appointments.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function utcRange(Carbon $fromDay, Carbon $toDay): array
    {
        return [
            $fromDay->copy()->startOfDay()->utc(),
            $toDay->copy()->endOfDay()->utc(),
        ];
    }

    /** A stored (UTC) appointment time as a clinic-timezone H:i label. */
    private function localTime(mixed $datetime): string
    {
        return Carbon::parse($datetime, 'UTC')->setTimezone(self::timezone())->format('H:i');
    }

    /**
     * @param  Carbon  $date  midnight of the day, in the clinic timezone
     * @param  Collection<int, DoctorScheduleException>  $exceptions
     * @param  array<int, string>  $bookedTimes  H:i in the clinic timezone
     * @return array<int, array{time: string, datetime: string, available: bool}>
     */
    private function buildSlots(Carbon $date, ?DoctorAvailability $availability, Collection $exceptions, array $bookedTimes): array
    {
        $startTime = self::DEFAULT_START;
        $endTime = self::DEFAULT_END;
        $isActive = ! in_array($date->dayOfWeek, self::DEFAULT_OFF_DAYS);

        if ($availability) {
            $startTime = $availability->start_time;
            $endTime = $availability->end_time;
            $isActive = $availability->is_active;
        }

        $disabledTimes = $exceptions->where('type', 'disabled')->pluck('time')->map(fn ($t) => Carbon::parse($t)->format('H:i'))->toArray();
        $addedTimes = $exceptions->where('type', 'added')->pluck('time')->map(fn ($t) => Carbon::parse($t)->format('H:i'))->toArray();

        // If clinic is NOT active that day, only the "added" custom slots (if any) remain
        $rawSlots = $isActive ? $this->generateSlots($date, $startTime, $endTime) : [];

        foreach ($addedTimes as $timeStr) {
            if (! in_array($timeStr, $rawSlots)) {
                $rawSlots[] = $timeStr;
            }
        }

        $finalRawSlots = array_values(array_filter($rawSlots, fn ($timeStr) => ! in_array($timeStr, $disabledTimes)));

        usort($finalRawSlots, fn ($a, $b) => strcmp($a, $b));

        $slots = [];
        foreach ($finalRawSlots as $timeStr) {
            $parts = explode(':', $timeStr);
            $dt = $date->copy()->setTime((int) $parts[0], (int) $parts[1]);

            $slots[] = [
                'time' => $timeStr,
                'datetime' => $dt->utc()->toIso8601String(),
                'available' => ! in_array($timeStr, $bookedTimes),
            ];
        }

        return $slots;
    }

    /**
     * @return array<int, string>
     */
    private function generateSlots(Carbon $date, string $startTime, string $endTime): array
    {
        $slots = [];

        $startParts = explode(':', $startTime);
        $endParts = explode(':', $endTime);

        $current = $date->copy()->setTime((int) $startParts[0], (int) $startParts[1]);
        $end = $date->copy()->setTime((int) $endParts[0], (int) $endParts[1]);

        while ($current < $end) {
            $slots[] = $current->format('H:i');
            $current->addMinutes(self::SLOT_DURATION);
        }

        return $slots;
    }
}
