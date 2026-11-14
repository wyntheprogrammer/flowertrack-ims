<?php

namespace App\Services;

use App\Models\BatchDailyObservation;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class SpoilageProfileService
{
    /**
     * Build historical rates from eligible completed daily records.
     */
    public function build(
        string $asOfDate,
        string $baseUnit,
        ?int $excludeBatchId = null
    ): array {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $asOfDate);

        if (! $date || $date->format('Y-m-d') !== $asOfDate) {
            throw new InvalidArgumentException(
                'The forecast date must use YYYY-MM-DD.'
            );
        }

        if (! in_array($baseUnit, ['Stem', 'Bouquet'], true)) {
            throw new InvalidArgumentException(
                'The base unit must be Stem or Bouquet.'
            );
        }

        // Only completed outcomes strictly before the forecast date.
        $query = BatchDailyObservation::query()
            ->where('is_complete', true)
            ->whereNull('exclusion_reason')
            ->where('base_unit', $baseUnit)
            ->whereNotNull('storage_condition')
            ->where('storage_condition', '<>', '')
            ->where('observation_date', '<', $date->toDateString())
            ->orderBy('batch_id')
            ->orderBy('observation_date');

        // Keep the target batch out of its older-batch reference profiles.
        if ($excludeBatchId !== null) {
            $query->where('batch_id', '<>', $excludeBatchId);
        }

        $groups = [];
        $supportedFlowers = [];
        $sourceRows = [];

        $totalOpening = 0;
        $totalSpoiled = 0;
        $totalReleased = 0;
        $totalAfterSpoilage = 0;

        foreach ($query->get() as $row) {
            $opening = $row->opening_quantity;
            $spoiled = $row->spoiled_quantity;
            $released = $row->released_quantity;
            $closing = $row->closing_quantity;

            // Reject inconsistent records instead of converting them to zero.
            if (
                $opening <= 0
                || $spoiled < 0
                || $released < 0
                || $closing < 0
                || $opening - $spoiled - $released !== $closing
                || $row->harvest_date->gt($row->received_date)
                || $row->received_date->gt($row->observation_date)
            ) {
                continue;
            }

            $age = (int) $row->harvest_date->diffInDays(
                $row->observation_date
            );

            $key = json_encode([
                $row->flower_id,
                $row->storage_condition,
                $age,
            ], JSON_THROW_ON_ERROR);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'flower_id' => $row->flower_id,
                    'storage_condition' => $row->storage_condition,
                    'age_days' => $age,
                    'opening' => 0,
                    'spoiled' => 0,
                    'released' => 0,
                    'after_spoilage' => 0,
                    'daily_rows' => 0,
                ];
            }

            // Pool quantities before calculating rates.
            $groups[$key]['opening'] += $opening;
            $groups[$key]['spoiled'] += $spoiled;
            $groups[$key]['released'] += $released;
            $groups[$key]['after_spoilage'] += $opening - $spoiled;
            $groups[$key]['daily_rows']++;

            $supportedFlowers[$row->flower_id] = true;

            $totalOpening += $opening;
            $totalSpoiled += $spoiled;
            $totalReleased += $released;
            $totalAfterSpoilage += $opening - $spoiled;

            // Preserve the inputs used to build this reference snapshot.
            $sourceRows[] = [
                $row->batch_id,
                $row->flower_id,
                $row->observation_date->toDateString(),
                $row->harvest_date->toDateString(),
                $row->received_date->toDateString(),
                $row->storage_condition,
                $opening,
                $spoiled,
                $released,
                $closing,
            ];
        }

        foreach ($groups as &$group) {
            $group['spoil_rate'] =
                $group['spoiled'] / $group['opening'];

            $group['release_rate'] = $group['after_spoilage'] > 0
                ? $group['released'] / $group['after_spoilage']
                : 0.0;
        }

        unset($group);

        return [
            'as_of_date' => $asOfDate,
            'base_unit' => $baseUnit,
            'daily_rows' => count($sourceRows),
            'supported_flower_ids' => array_keys($supportedFlowers),
            'groups' => array_values($groups),

            // No history means unavailable rates, not zero spoilage.
            'global_rates' => $totalOpening > 0 ? [
                'spoil_rate' => $totalSpoiled / $totalOpening,
                'release_rate' => $totalReleased
                    / max(1, $totalAfterSpoilage),
            ] : null,

            'source_hash' => hash(
                'sha256',
                json_encode($sourceRows, JSON_THROW_ON_ERROR)
            ),
        ];
    }
}