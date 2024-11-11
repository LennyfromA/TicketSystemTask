<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $guarded = false;

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }
}
