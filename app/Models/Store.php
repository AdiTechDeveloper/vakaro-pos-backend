<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'address',
        'state',
        'phone',
        'contact_person_name',
        'gstin',
        'tagline',
        'logo',

        // Pharmacy columns
        'drug_licence_no',
        'pharmacist_name',
        'pharmacist_reg_no',

        // Subscription / access control
        'status',
        'status_reason',
        'status_changed_at',
        'subscription_ends_at',
    ];

    public const SUSPENDED_MESSAGE = 'Your subscription has ended. Please subscribe to log in.';

    protected $casts = [
        'status_changed_at' => 'datetime',
        'subscription_ends_at' => 'date',
    ];

    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        return ! $this->subscription_ends_at
            || $this->subscription_ends_at->copy()->endOfDay()->isFuture();
    }

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }

    public function users()
    {
        return $this->hasMany(User::class, 'store_id')->withTrashed();
    }
}
