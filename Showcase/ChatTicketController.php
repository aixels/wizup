<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Models\AutoReply;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\ChatTicket;
use App\Models\ChatTicketLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\ChatService;
use App\Services\WhatsappService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Redirect;

class ChatTicketController extends BaseController
{
    public function index(Request $request, $uuid = null)
    {
        //
    }

    public function update(Request $request, $uuid)
    { 
        $contact = Contact::where('uuid', $uuid)->first();

        ChatTicket::where('contact_id', $contact->id)->update([
            'status' => $request->status,
            'assigned_to' => auth()->user()->id
        ]);

        $statusDescription = $request->status == 'closed' ? 'opened to closed' : 'closed to open';

        $ticketId = ChatTicketLog::insertGetId([
            'contact_id' => $contact->id,
            'description' => 'Conversation was moved from ' . $statusDescription,
            'created_at' => now()
        ]);

        ChatLog::insert([
            'contact_id' => $contact->id,
            'entity_type' => 'ticket',
            'entity_id' => $ticketId,
            'created_at' => now()
        ]);

        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Status updated successfully!')
            ]
        );
    }

    public function assign(Request $request, $uuid)
    { 
        $contact = Contact::where('uuid', $uuid)->first();
        $team = Team::where('organization_id', session()->get('current_organization'))->where('user_id', $request->id)->first();
        $user = User::where('id', $request->id)->first();
            
        if($team && $user){
            ChatTicket::where('contact_id', $contact->id)->update([
                'assigned_to' => $request->id
            ]);

            $ticketId = ChatTicketLog::insertGetId([
                'contact_id' => $contact->id,
                'description' => 'Conversation was assigned to ' . $user->first_name . ' ' . $user->last_name,
                'created_at' => now()
            ]);

            ChatLog::insert([
                'contact_id' => $contact->id,
                'entity_type' => 'ticket',
                'entity_id' => $ticketId,
                'created_at' => now()
            ]);

            return Redirect::back()->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('Ticket assigned successfully!')
                ]
            );
        }
    }
}