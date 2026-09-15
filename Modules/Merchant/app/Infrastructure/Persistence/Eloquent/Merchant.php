<?php

declare(strict_types=1);

namespace Modules\Merchant\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Merchant\Database\Factories\MerchantFactory;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 */
#[Fillable(['slug', 'name'])]
class Merchant extends Model
{
    /** @use HasFactory<MerchantFactory> */
    use HasFactory;

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    protected static function newFactory(): MerchantFactory
    {
        return MerchantFactory::new();
    }
}
