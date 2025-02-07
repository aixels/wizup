<?php

namespace App\Models;

use App\Helpers\DateTimeHelper;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketComment extends Model {
    use HasFactory;

    protected $guarded = [];
    public $timestamps = false;

    public function getCreatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function getUpdatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function user(){
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function ticket(){
        return $this->belongsTo(Ticket::class, 'ticket_id', 'id');
    }
}
