<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecentItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'item_type',
        'item_id',
        'label',
        'url',
        'visited_at',
    ];

    protected $casts = [
        'visited_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
