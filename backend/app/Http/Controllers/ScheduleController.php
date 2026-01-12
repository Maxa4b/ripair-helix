<?php

namespace App\Http\Controllers;

use App\Models\HelixAvailabilityBlock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduleController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'start' => ['required', 'date'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:14'],
                        'lead_min' => ['sometimes', 'integer', 'min:0', 'max:1440'],
        ]);

        $startDate = CarbonImmutable::parse($validated['start'], 'Europe/Paris')->startOfDay();
        $days = isset($validated['days']) ? (int) $validated['days'] : 7;
        $duration = isset($validated['duration_min']) ? (int) $validated['duration_min'] : 60;
        $leadMin = isset($validated['lead_min']) ? (int) $validated['lead_min'] : 0;

        $endDate = $startDate->addDays($days);

        $openingHours = DB::table('opening_hours')->get()->keyBy('weekday');
        $promoRules = DB::table('promo_rules')->get()->groupBy('weekday');

        $statuses = ['booked', 'confirmed', 'in_progress'];
        $appointments = DB::table('appointments')
            ->select('start_datetime', 'end_datetime')
            ->whereIn('status', $statuses)
            ->where('start_datetime', '>=', $startDate->format('Y-m-d 00:00:00'))
            ->where('start_datetime', '<', $endDate->format('Y-m-d 00:00:00'))
            ->get()
            ->groupBy(function ($row) {
                return substr($row->start_datetime, 0, 10);
            });

        $blocks = HelixAvailabilityBlock::query()
            ->where('type', '!=', 'open')
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_datetime', [$startDate->format('Y-m-d 00:00:00'), $endDate->format('Y-m-d 23:59:59')])
                    ->orWhereBetween('end_datetime', [$startDate->format('Y-m-d 00:00:00'), $endDate->format('Y-m-d 23:59:59')])
                    ->orWhere(function ($query) use ($startDate, $endDate) {
                        $query->where('start_datetime', '<', $startDate->format('Y-m-d 00:00:00'))
                            ->where('end_datetime', '>', $endDate->format('Y-m-d 23:59:59'));
                    });
            })
            ->get()
            ->groupBy(function ($row) {
                return substr($row->start_datetime, 0, 10);
            });

        $now = CarbonImmutable::now('Europe/Paris');
        $leadLimit = $now->addMinutes($leadMin);

        $output = [];

        for ($i = 0; $i < $days; $i++) {
            $currentDate = $startDate->addDays($i);
            $dateKey = $currentDate->format('Y-m-d');
            $weekday = (int)$currentDate->format('w');
            $config = $openingHours->get($weekday);

            if (!$config) {
                $output[] = [
                    'date' => $dateKey,
                    'slots' => [],
                ];
                continue;
            }

            $slotStep = (int)($config->slot_step_min ?? 30);
            if ($slotStep < 5) {
                $slotStep = 5;
            }
            $periods = [];
            if (!empty($config->morning_start) && !empty($config->morning_end)) {
                $periods[] = [$config->morning_start, $config->morning_end];
            }
            if (!empty($config->afternoon_start) && !empty($config->afternoon_end)) {
                $periods[] = [$config->afternoon_start, $config->afternoon_end];
            }

            $dayAppointments = $appointments->get($dateKey) ?? collect();
            $dayBlocks = $blocks->get($dateKey) ?? collect();
            $slotToggleBlocks = [];
            $otherBlocks = [];

            foreach ($dayBlocks as $blockRecord) {
                if ($blockRecord->notes === 'slot_toggle') {
                    $key = (string) $blockRecord->start_datetime;
                    $slotToggleBlocks[$key] = $blockRecord;
                } else {
                    $otherBlocks[] = $blockRecord;
                }
            }

            $slots = [];

            foreach ($periods as [$startTime, $endTime]) {
                $openStart = CarbonImmutable::parse("$dateKey $startTime", 'Europe/Paris');
                $openEnd = CarbonImmutable::parse("$dateKey $endTime", 'Europe/Paris');

                for ($slotStart = $openStart; $slotStart < $openEnd; $slotStart = $slotStart->addMinutes($slotStep)) {
                    $slotEnd = $slotStart->addMinutes($duration);
                    $slotWindowEnd = $slotStart->addMinutes($slotStep);
                    if ($slotEnd > $openEnd) {
                        break;
                    }
                    if ($slotWindowEnd > $openEnd) {
                        $slotWindowEnd = $openEnd;
                    }

                    $status = 'available';
                    $toggleable = true;
                    $blockId = null;

                    if ($slotEnd <= $slotStart || $slotStart < $leadLimit) {
                        $status = 'past';
                        $toggleable = false;
                    }

                    foreach ($dayAppointments as $appointment) {
                        $appointmentStart = CarbonImmutable::parse($appointment->start_datetime, 'Europe/Paris');
                        $appointmentEnd = CarbonImmutable::parse($appointment->end_datetime, 'Europe/Paris');

                        if ($slotStart < $appointmentEnd && $slotEnd > $appointmentStart) {
                            $status = 'booked';
                            $toggleable = false;
                            break;
                        }
                    }

                    if ($status !== 'booked') {
                        $slotKey = $slotStart->format('Y-m-d H:i:s');
                        if (isset($slotToggleBlocks[$slotKey])) {
                            $block = $slotToggleBlocks[$slotKey];
                            $status = 'closed';
                            $blockId = (int) $block->id;
                            $toggleable = true;
                        } else {
                            foreach ($otherBlocks as $block) {
                                $blockStart = CarbonImmutable::parse($block->start_datetime, 'Europe/Paris');
                                $blockEnd = CarbonImmutable::parse($block->end_datetime, 'Europe/Paris');

                                if ($slotStart < $blockEnd && $slotEnd > $blockStart) {
                                    $status = 'closed';
                                    $blockId = (int) $block->id;
                                    $toggleable = false;
                                    break;
                                }
                            }
                        }
                    }

                    $discount = 0;
                    foreach ($promoRules->get($weekday, []) as $promo) {
                        $promoStart = CarbonImmutable::parse("$dateKey {$promo->start_time}", 'Europe/Paris');
                        $promoEnd = CarbonImmutable::parse("$dateKey {$promo->end_time}", 'Europe/Paris');
                        if ($slotStart >= $promoStart && $slotEnd <= $promoEnd) {
                            $discount = (int)($promo->discount_pct ?? 0);
                            break;
                        }
                    }

                    $slots[] = [
                        'time' => $slotStart->format('H:i'),
                        'start' => $slotStart->format('Y-m-d H:i:s'),
                        'end' => $slotEnd->format('Y-m-d H:i:s'),
                        'toggle_end' => $slotWindowEnd->format('Y-m-d H:i:s'),
                        'status' => $status,
                        'toggleable' => $toggleable,
                        'block_id' => $blockId,
                        'discount' => $discount,
                        'step_minutes' => $slotStep,
                    ];
                }
            }

            $output[] = [
                'date' => $dateKey,
                'slots' => $slots,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $output,
        ]);
    }

    public function toggle(Request $request)
    {
        $data = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['sometimes', 'date', 'after:start'],
            'make_available' => ['required', 'boolean'],
            'block_id' => ['sometimes', 'nullable', 'integer', 'exists:helix_availability_blocks,id'],
            'step_minutes' => ['sometimes', 'integer', 'min:5', 'max:480'],
                    ]);

        $user = $request->user();
        $start = CarbonImmutable::parse($data['start'], 'Europe/Paris');
        $stepOverride = isset($data['step_minutes']) ? (int) $data['step_minutes'] : null;
        $weekday = (int)$start->format('w');
        $config = DB::table('opening_hours')->where('weekday', $weekday)->first();
        $slotStep = $this->resolveStepMinutes($config, $stepOverride);
        $toggleEnd = $this->clampSlotEnd($start, $slotStep, $config);

        if ($data['make_available']) {
            // Rouvrir un créneau: supprimer tous les blocs slot_toggle qui chevauchent l'intervalle demandé
            $endParam = $data['end'] ?? $toggleEnd->format('Y-m-d H:i:s');
            $end = CarbonImmutable::parse($endParam, 'Europe/Paris');

            // Si un block_id précis est fourni, on tente de le supprimer en priorité
            if (!empty($data['block_id'])) {
                HelixAvailabilityBlock::query()
                    ->where('notes', 'slot_toggle')
                    ->where('id', (int) $data['block_id'])
                    ->delete();
            }

            // Supprime tous les blocs slot_toggle qui se chevauchent avec [start, end]
            HelixAvailabilityBlock::query()
                ->where('notes', 'slot_toggle')
                ->where(function ($q) use ($start, $end) {
                    $from = $start->format('Y-m-d H:i:s');
                    $to = $end->format('Y-m-d H:i:s');
                    $q->whereBetween('start_datetime', [$from, $to])
                        ->orWhereBetween('end_datetime', [$from, $to])
                        ->orWhere(function ($inner) use ($from, $to) {
                            $inner->where('start_datetime', '<', $from)
                                  ->where('end_datetime', '>', $to);
                        });
                })
                ->delete();

            return response()->json(['success' => true]);
        }

        // Fermer un créneau : bloc exactement sur le pas [start, toggleEnd]
        $blockStart = $start->seconds(0);
        $blockEnd = $toggleEnd->seconds(0);

        // Défaut de garde : si clampSlotEnd a renvoyé le même instant (ou un instant avant),
        // on force une durée minimale équivalente au pas pour éviter un bloc vide.
        if ($blockEnd->lessThanOrEqualTo($blockStart)) {
            $blockEnd = $blockStart->addMinutes($slotStep);
        }

        $blockStartKey = $blockStart->format('Y-m-d H:i:s');
        $blockEndKey = $blockEnd->format('Y-m-d H:i:s');

        $existing = HelixAvailabilityBlock::query()
            ->where('notes', 'slot_toggle')
            ->where('start_datetime', $blockStartKey)
            ->where('end_datetime', $blockEndKey)
            ->exists();

        if ($existing) {
            return response()->json(['success' => true]);
        }

        HelixAvailabilityBlock::create([
            'created_by' => $user?->id ?? 1,
            'type' => 'closed',
            'title' => 'Indisponible',
            'start_datetime' => $blockStartKey,
            'end_datetime' => $blockEndKey,
            'color' => null,
            'notes' => 'slot_toggle',
        ]);

        return response()->json(['success' => true]);
    }

    private function resolveStepMinutes(?object $config, ?int $override): int
    {
        if ($override !== null && $override >= 5) {
            return $override;
        }

        if ($config && !empty($config->slot_step_min)) {
            $step = (int)$config->slot_step_min;
            return $step >= 5 ? $step : 5;
        }

        return 30;
    }

    private function clampSlotEnd(CarbonImmutable $start, int $stepMinutes, ?object $config): CarbonImmutable
    {
        $candidate = $start->addMinutes($stepMinutes);

        if (!$config) {
            return $candidate;
        }

        $date = $start->format('Y-m-d');
        $segments = [
            ['start' => $config->morning_start ?? null, 'end' => $config->morning_end ?? null],
            ['start' => $config->afternoon_start ?? null, 'end' => $config->afternoon_end ?? null],
        ];

        foreach ($segments as $segment) {
            if (empty($segment['start']) || empty($segment['end'])) {
                continue;
            }

            $segmentStart = CarbonImmutable::parse("$date {$segment['start']}", 'Europe/Paris');
            $segmentEnd = CarbonImmutable::parse("$date {$segment['end']}", 'Europe/Paris');

            if ($start >= $segmentStart && $start < $segmentEnd) {
                if ($candidate > $segmentEnd) {
                    return $segmentEnd;
                }

                return $candidate;
            }
        }

        return $candidate;
    }
}
