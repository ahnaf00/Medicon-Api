<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Hospital extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'address',
        'latitude',
        'longitude',
        'emergency_phone',
        'is_24_7',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'is_24_7' => 'boolean',
        'is_active' => 'boolean',
        'deactivated_at' => 'datetime',
    ];

    /** Hospitals patients can see; admins hide the rest (audit L2b step 3). */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Name or address contains the term. Grouped, so it can be combined with other filters. */
    #[Scope]
    protected function matching(Builder $query, string $term): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('name', 'like', "%{$term}%")
            ->orWhere('address', 'like', "%{$term}%"));
    }
}
