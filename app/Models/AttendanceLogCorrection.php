<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Corrección manual de una checada. La checada (attendance_logs) nunca se modifica.
 */
class AttendanceLogCorrection extends Model
{
    public const TYPE_IGNORE = 'ignore';

    public const TYPES = [
        AttendanceLog::EVENT_CHECK_IN => 'Entrada',
        AttendanceLog::EVENT_BREAK_OUT => 'Salida a comer',
        AttendanceLog::EVENT_BREAK_IN => 'Regreso de comer',
        AttendanceLog::EVENT_CHECK_OUT => 'Salida',
        self::TYPE_IGNORE => 'No cuenta',
    ];

    protected $fillable = ['attendance_log_id', 'corrected_type', 'reason', 'user_id'];

    public function attendanceLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceLog::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
