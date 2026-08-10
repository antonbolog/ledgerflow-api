<?php

declare(strict_types=1);

namespace Modules\Merchant\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Merchant\Database\Factories\MerchantFactory;

/**
 * The merchant a payment belongs to.
 *
 * Plain Eloquent, deliberately: this context has no invariant beyond the
 * database's own, and a domain entity, identifier value object or repository
 * interface with no use case behind it would be structure without meaning.
 *
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

    /**
     * Module models cannot use the framework's Database\Factories guess, which
     * assumes a single root factory namespace.
     */
    protected static function newFactory(): MerchantFactory
    {
        return MerchantFactory::new();
    }
}
