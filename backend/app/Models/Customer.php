<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Gender;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $customer_name
 * @property string $customer_address
 * @property Gender $gender
 * @property \Carbon\CarbonImmutable|null $birth_date
 * @property string|null $phone
 * @property string|null $email
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['customer_name', 'customer_address', 'gender', 'birth_date', 'phone', 'email'];

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'birth_date' => 'immutable_date',
        ];
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
