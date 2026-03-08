<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use App\Http\Traits\ModelOwnedByUserTrait;
use Database\Factories\PiggyBankFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * App\Models\PiggyBank
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property int $target_amount
 * @property int $current_amount
 * @property Carbon|null $start_date
 * @property Carbon|null $target_date
 * @property string|null $notes
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read float $percentage
 * @method static Builder|PiggyBank active()
 * @method static PiggyBankFactory factory(...$parameters)
 * @method static Builder|PiggyBank newModelQuery()
 * @method static Builder|PiggyBank newQuery()
 * @method static Builder|PiggyBank query()
 */
class PiggyBank extends Model
{
    use HasFactory;
    use ModelOwnedByUserTrait;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'target_amount',
        'current_amount',
        'start_date',
        'target_date',
        'notes',
        'active',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<string>
     */
    protected $appends = [
        'percentage',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_amount' => 'float',
            'current_amount' => 'float',
            'start_date' => 'date',
            'target_date' => 'date',
            'active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the percentage of the goal that has been saved.
     */
    public function getPercentageAttribute(): float
    {
        if ($this->target_amount <= 0) {
            return 0;
        }

        return min(100, round(($this->current_amount / $this->target_amount) * 100, 2));
    }

    /**
     * Scope a query to only include active piggy banks.
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
