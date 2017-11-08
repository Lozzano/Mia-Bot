<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameParticipant extends Model
{
    protected $table = 'game_participants';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'participant_order'
    ];
}
