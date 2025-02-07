<?php

namespace App\Models;

use App\Helpers\DateTimeHelper;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model {
    use HasFactory;
    use HasUuid;

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

    public function category(){
        return $this->belongsTo(TicketCategory::class, 'category_id', 'id');
    }

    public function user(){
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function agent(){
        return $this->belongsTo(User::class, 'assigned_to', 'id');
    }

    public function comments(){
        return $this->hasMany(TicketComment::class)->orderBy('created_at', 'desc');
    }

    public function commentsWithUser(){
        return $this->comments()->with('user');
    }
}

