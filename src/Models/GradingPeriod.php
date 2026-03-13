<?php

namespace JarredCain\CanvasLms\Models;

use Carbon\Carbon;

/**
 * @property string      $id
 * @property string      $title
 * @property float|null  $weight
 * @property bool        $is_last
 * @property bool        $is_closed
 * @property \Carbon\Carbon|null $start_date
 * @property \Carbon\Carbon|null $end_date
 * @property \Carbon\Carbon|null $close_date
 */
class GradingPeriod extends CanvasModel
{
    protected static string $endpoint = 'grading_periods';

    protected static bool $requiresContext = true;

    protected array $casts = [
        'id'         => 'string',
        'weight'     => 'float',
        'is_last'    => 'bool',
        'is_closed'  => 'bool',
        'start_date' => 'datetime',
        'end_date'   => 'datetime',
        'close_date' => 'datetime',
    ];

    /**
     * Whether the grading period is currently open (before its close date).
     */
    public function isOpen(): bool
    {
        $closeDate = $this->close_date;

        if (!$closeDate instanceof Carbon) {
            return false;
        }

        return Carbon::now()->lt($closeDate);
    }

    /**
     * Whether the grading period is closed (at or past its close date).
     */
    public function isClosed(): bool
    {
        return !$this->isOpen();
    }
}
