<?php

namespace App\Models;

use App\Helpers\DateTimeHelper;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Propaganistas\LaravelPhone\PhoneNumber;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $guarded = [];
    protected $appends = ['full_name', 'formatted_phone_number'];
    protected $dates = ['deleted_at'];
    public $timestamps = false;
    protected $with = ['teamTag'];

    public function getCreatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function getUpdatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function getAllContacts($organizationId, $searchTerm)
    {
        return $this->with('contactGroup')
            ->where('organization_id', $organizationId)
            ->where('deleted_at', null)
            ->where(function ($query) use ($searchTerm) {
                $query->where('first_name', 'like', '%' . $searchTerm . '%')
                    ->orwhere('last_name', 'like', '%' . $searchTerm . '%')
                    ->orwhere('email', 'like', '%' . $searchTerm . '%')
                    ->orwhere('phone', 'like', '%' . $searchTerm . '%');
            })
            ->orderByDesc('is_favorite')
            ->latest()
            ->orderBy('id')
            ->paginate(10);
    }

    public function getAllContactGroups($organizationId)
    {
        return ContactGroup::where('organization_id', $organizationId)->whereNull('deleted_at')->get();
    }

    public function countContacts($organizationId)
    {
        return $this->where('organization_id', $organizationId)->whereNull('deleted_at')->count();
    }

    public function contactGroup()
    {
        return $this->belongsTo(ContactGroup::class, 'contact_group_id', 'id')->withTrashed();
    }

    public function notes()
    {
        return $this->hasMany(ChatNote::class, 'contact_id')->whereNull('deleted_at')->orderBy('created_at', 'desc');
    }

    public function chats()
    {
        return $this->hasMany(Chat::class, 'contact_id')->orderBy('created_at', 'asc');
    }

    public function lastChat()
    {
        return $this->hasOne(Chat::class, 'contact_id')->with('media')->latest();
    }

    public function lastInboundChat()
    {
        return $this->hasOne(Chat::class, 'contact_id')
            ->where('type', 'inbound')
            ->with('media')
            ->latest();
    }
    public function teamTag()
    {
        return $this->belongsTo(TeamTag::class, 'team_tag_id', 'id');
    }
    public function chatLogs()
    {
        return $this->hasMany(ChatLog::class);
    }

    public function contactsWithChats($organizationId, $searchTerm = null, $ticketingActive = false, $ticketState = null, $ticketTag = null, $sortDirection = 'asc', $role = 'owner', $allowAgentsViewAllChats = true)
    {
        $query = $this->newQuery()
            ->where('contacts.organization_id', $organizationId)
            ->whereNotNull('contacts.latest_chat_created_at')
            ->whereNull('contacts.deleted_at')
            ->with(['lastChat', 'lastInboundChat'])
            ->select('contacts.*')
            ->orderBy('contacts.latest_chat_created_at', $sortDirection);

        if ($ticketingActive) {
            // Conditional join with chat_tickets table and comparison with ticketState

            if ($ticketState === 'unassigned') {
                $query->leftJoin('chat_tickets', 'contacts.id', '=', 'chat_tickets.contact_id')
                    ->whereNull('chat_tickets.assigned_to');
            } elseif ($ticketState !== null && $ticketState !== 'all') {
                $query->leftJoin('chat_tickets', 'contacts.id', '=', 'chat_tickets.contact_id')
                    ->where('chat_tickets.status', $ticketState);
            } else if ($ticketState === 'all') {
                $query->leftJoin('chat_tickets', 'contacts.id', '=', 'chat_tickets.contact_id');
            }

            if ($role == 'agent' && $allowAgentsViewAllChats == false) {
                $query->where(function ($q) {
                    $q->where('chat_tickets.assigned_to', auth()->user()->id);
                });
            }
        }
        if (!empty($ticketTag)) {
            $query->where('contacts.team_tag_id', $ticketTag);
        }
        // Include the search term in the query if provided
        if ($searchTerm) {
            $query->where(function ($q) use ($searchTerm) {
                $q->where(function ($qq) use ($searchTerm) {
                    $qq->whereRaw("CONCAT(contacts.first_name, ' ', contacts.last_name) LIKE ?", ['%' . $searchTerm . '%']);
                })
                    ->orWhere('contacts.phone', 'like', '%' . $searchTerm . '%')
                    ->orWhere('contacts.email', 'like', '%' . $searchTerm . '%');
            });
        }

        return $query->paginate(10);
    }

    public function contactsWithChatsCount($organizationId, $searchTerm = null, $ticketingActive = false, $ticketState = null, $ticketTag = null, $sortDirection = 'asc', $role = 'owner', $allowAgentsViewAllChats = true)
    {
        $query = $this->newQuery()
            ->where('contacts.organization_id', $organizationId)
            ->whereNotNull('contacts.latest_chat_created_at')
            ->whereNull('contacts.deleted_at')
            ->with(['lastChat', 'lastInboundChat'])
            ->select('contacts.*')
            ->orderBy('contacts.latest_chat_created_at', $sortDirection);

        if ($ticketingActive) {

            // Conditional join with chat_tickets table and comparison with ticketState
            if ($ticketState === 'unassigned') {
                $query->leftJoin('chat_tickets', 'contacts.id', '=', 'chat_tickets.contact_id')
                    ->whereNull('chat_tickets.assigned_to');
            } elseif ($ticketState !== null && $ticketState !== 'all') {
                $query->leftJoin('chat_tickets', 'contacts.id', '=', 'chat_tickets.contact_id')
                    ->where('chat_tickets.status', $ticketState);
            } else if ($ticketState === 'all') {
                $query->leftJoin('chat_tickets', 'contacts.id', '=', 'chat_tickets.contact_id');
            }

            if ($role == 'agent' && $allowAgentsViewAllChats == false) {
                $query->where(function ($q) {
                    $q->where('chat_tickets.assigned_to', auth()->user()->id);
                });
            }
        }
        if (!empty($ticketTag)) {
            $query->where('contacts.team_tag_id', $ticketTag);
        }
        if ($role == 'agent' && $allowAgentsViewAllChats == false) {
            $query->where(function ($q) {
                $q->whereNull('chat_tickets.assigned_to')
                    ->orWhere('chat_tickets.assigned_to', auth()->user()->id);
            });
        }

        // Include the search term in the query if provided
        if ($searchTerm) {
            $query->where(function ($q) use ($searchTerm) {
                $q->where(function ($qq) use ($searchTerm) {
                    $qq->whereRaw("CONCAT(contacts.first_name, ' ', contacts.last_name) LIKE ?", ['%' . $searchTerm . '%']);
                })
                    ->orWhere('contacts.phone', 'like', '%' . $searchTerm . '%')
                    ->orWhere('contacts.email', 'like', '%' . $searchTerm . '%');
            });
        }

        return $query->count();
    }

    public function getFirstNameAttribute()
    {
        $firstName = $this->attributes['first_name'];
        $firstName = $this->decodeUnicodeBytes($firstName);

        return $firstName;
    }

    public function getLastNameAttribute()
    {
        $lastName = $this->attributes['last_name'];
        $lastName = $this->decodeUnicodeBytes($lastName);

        return $lastName;
    }

    public function getFullNameAttribute()
    {
        $firstName = $this->attributes['first_name'];
        $lastName = $this->attributes['last_name'];

        // Convert byte sequences to Unicode characters
        $firstName = $this->decodeUnicodeBytes($firstName);
        $lastName = $this->decodeUnicodeBytes($lastName);

        // Return the full name combining first name and last name
        return $firstName . ' ' . $lastName;

        //return "{$this->first_name} {$this->last_name}";
    }

    public function getFormattedPhoneNumberAttribute($value)
    {
        // Use the phone() helper function to format the phone number to international format
        return phone($this->phone)->formatInternational();
    }

    protected function decodeUnicodeBytes($value)
    {
        return preg_replace_callback('/\\\\x([0-9A-F]{2})/i', function ($matches) {
            return chr(hexdec($matches[1]));
        }, $value);
    }
}
