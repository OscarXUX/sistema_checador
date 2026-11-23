<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de medios de marcaje aceptados por la empresa y detectados en la terminal Hikvision.
 */
class MarkingMethod extends Model
{
    use HasFactory;

    public const CODE_FINGERPRINT = 'fingerprint';

    public const CODE_FACE = 'face';

    public const CODE_CARD = 'card';

    public const CODE_PIN = 'pin';

    public const CODES = [
        self::CODE_FINGERPRINT,
        self::CODE_FACE,
        self::CODE_CARD,
        self::CODE_PIN,
    ];

    protected $fillable = [
        'code',
        'name',
        'is_enabled',
        'is_biometric',
        'requires_privacy_notice',
        'privacy_notice_code',
        'device_supported',
        'last_detected_at',
        'device_details',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_biometric' => 'boolean',
            'requires_privacy_notice' => 'boolean',
            'device_supported' => 'boolean',
            'last_detected_at' => 'datetime',
            'device_details' => 'array',
        ];
    }

    public function isBlockedByPrivacyNotice(): bool
    {
        return $this->requires_privacy_notice && $this->privacy_notice_code === 'TE-02';
    }

    public static function isMethodEnabled(string $code): bool
    {
        $method = static::where('code', $code)->first();

        if ($method === null) {
            return false;
        }

        return $method->is_enabled && ! $method->isBlockedByPrivacyNotice();
    }
}
