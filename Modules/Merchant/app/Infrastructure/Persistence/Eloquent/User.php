<?php

declare(strict_types=1);

namespace Modules\Merchant\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Modules\Merchant\Database\Factories\UserFactory;
use Override;

/**
 * An authenticated merchant user.
 *
 * Lives in Infrastructure because it is an Eloquent model: Domain stays free of
 * framework types, and this context has no domain behaviour yet that would
 * justify a separate entity. Consumers depend on the Application-layer merchant
 * contract, not on this class.
 *
 * The @property list is what types attribute access under PHPStan level 10.
 * email_verified_at is declared as Carbon because casts() casts it to datetime;
 * without that line the analyser falls back to the raw column type and rejects
 * every assignment the cast makes legal.
 *
 * @property int $id
 * @property int $merchant_id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property Carbon|null $email_verified_at
 */
// merchant_id is deliberately NOT mass-assignable. Tenant ownership is the one
// attribute a request must never be able to set, and fill()/update() are exactly
// how request input reaches a model. It is assigned explicitly, by the seeder and
// by any future use case that has decided which merchant owns the row. Factories
// still set it: Eloquent factories run inside Model::unguarded().
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Module models cannot use the framework's Database\Factories guess, which
     * assumes a single root factory namespace.
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
