<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ship-type → role category mapping used by Spec 4 feature extraction.
 *
 * Categories: logi | bomber | command | tackle | mainline | other.
 * Any ship_type_id not in this table is treated as 'other' by the
 * extractor at compute time. The admin panel lets operators expand
 * the mapping without a migration.
 *
 * @property int $ship_type_id
 * @property string $category
 * @property \Illuminate\Support\Carbon $computed_at
 */
class ShipClassCategoryMapping extends Model
{
    protected $table = 'ship_class_category_mapping';

    protected $primaryKey = 'ship_type_id';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'computed_at' => 'datetime',
    ];

    public const CAT_LOGI = 'logi';
    public const CAT_BOMBER = 'bomber';
    public const CAT_COMMAND = 'command';
    public const CAT_TACKLE = 'tackle';
    public const CAT_MAINLINE = 'mainline';
    public const CAT_OTHER = 'other';

    /** @return array<int, string> */
    public static function categories(): array
    {
        return [
            self::CAT_LOGI,
            self::CAT_BOMBER,
            self::CAT_COMMAND,
            self::CAT_TACKLE,
            self::CAT_MAINLINE,
            self::CAT_OTHER,
        ];
    }

    /** @return array<string, string> */
    public static function categoryOptions(): array
    {
        return [
            self::CAT_LOGI => 'Logistics',
            self::CAT_BOMBER => 'Bomber',
            self::CAT_COMMAND => 'Command / FC',
            self::CAT_TACKLE => 'Tackle',
            self::CAT_MAINLINE => 'Mainline DPS',
            self::CAT_OTHER => 'Other (explicit)',
        ];
    }
}
