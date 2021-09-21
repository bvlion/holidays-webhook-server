<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Command extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['target_id', 'deleted_at'];
}
