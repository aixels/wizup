<?php

namespace App\Services;

use App\Events\NewChatEvent;
use App\Http\Resources\ContactResource;
use App\Models\Addon;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\ChatMedia;
use App\Models\ChatTicket;
use App\Models\ChatTicketLog;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Team;
use App\Models\Template;
use App\Services\SubscriptionService;
use App\Services\WhatsappService;
use App\Traits\TemplateTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Validator;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;

class ChatService
{
    use TemplateTrait;

    private $whatsappService;
    private $organizationId;

    public function __construct($organizationId)
    {
        $this->organizationId = $organizationId;
        $this->initializeWhatsappService();
    }

    private function initializeWhatsappService()
    {
        $config = Organization::where('id', $this->organizationId)->first()->metadata;
        $config = $config ? json_decode($config, true) : [];

        $accessToken = $config['whatsapp']['access_token'] ?? null;
        $apiVersion = config('graph.api_version');
        $appId = $config['whatsapp']['app_id'] ?? null;
        $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
        $wabaId = $config['whatsapp']['waba_id'] ?? null;

        $this->whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $this->organizationId);
    }

    public function getChatList($request, $uuid = null, $searchTerm = null)
    {
        $role = auth()->user()->teams[0]->role;
        $contact = new Contact;
        $unassigned = ChatTicket::where('assigned_to', NULL)->count();
        $closedCount = ChatTicket::where('status', 'closed')->count();
        $closedCount = ChatTicket::where('status', 'open')->count();
        $allCount = ChatTicket::count();
        $config = Organization::where('id', $this->organizationId)->first();
        $agents = Team::where('organization_id', $this->organizationId)->get();
        $ticketState = $request->status == null ? 'all' : $request->status;
        $ticketTag = !empty($request->tags) ? $request->tags : null;
        $sortDirection = $request->session()->get('chat_sort_direction') ?? 'desc';
        $allowAgentsToViewAllChats = true;
        $ticketingActive = false;
        $aiAssistantAddon = Addon::where('name', 'AI Assistant')->first();
        $aiAssistantSetting = Setting::where('key', 'ai_assistant')->first();
        $aimodule = $aiAssistantAddon && $aiAssistantAddon->status && $aiAssistantSetting && $aiAssistantSetting->value == 1;

        //Check if tickets module has been enabled
        if($config->metadata != NULL){
            $settings = json_decode($config->metadata);

            if(isset($settings->tickets) && $settings->tickets->active === true){
                $ticketingActive = true;

                //Check for chats that don't have corresponding chat ticket rows
                $contacts = $contact->contactsWithChats($this->organizationId, NULL);
                
                foreach($contacts as $contact){
                    ChatTicket::firstOrCreate(
                        ['contact_id' => $contact->id],
                        [
                            'assigned_to' => null,
                            'status' => 'open',
                            'updated_at' => now(),
                        ]
                    );
                }

                //Check if agents can view all chats
                $allowAgentsToViewAllChats = $settings->tickets->allow_agents_to_view_all_chats;
            }
        }

        // Retrieve the list of contacts with chats
        $contacts = $contact->contactsWithChats($this->organizationId, $searchTerm, $ticketingActive, $ticketState, $ticketTag, $sortDirection, $role, $allowAgentsToViewAllChats);
        $rowCount = $contact->contactsWithChatsCount($this->organizationId, $searchTerm, $ticketingActive, $ticketState, $ticketTag, $sortDirection, $role, $allowAgentsToViewAllChats);

        $pusherSettings = Setting::whereIn('key', [
            'pusher_app_id',
            'pusher_app_key',
            'pusher_app_secret',
            'pusher_app_cluster',
        ])->pluck('value', 'key')->toArray();

        $perPage = 10; // Number of items per page
        $totalContacts = count($contacts); // Total number of contacts
        $messageTemplates = Template::where('organization_id', $this->organizationId)
            ->where('deleted_at', null)
            ->where('status', 'APPROVED')
            ->get();

        if ($uuid !== null) {
            // If $uuid is provided, get the chat thread for a specific contact
            $contact = Contact::with(['lastChat', 'lastInboundChat', 'notes', 'contactGroup'])->where('uuid', $uuid)->first();
            $ticket = ChatTicket::with('user')->where('contact_id', $contact->id)->first();
            $chatLogs = ChatLog::where('contact_id', $contact->id)->where('deleted_at', null)->get();
            $chats = [];

            foreach ($chatLogs as $chatLog) {
                $chats[] = array([
                    'type' => $chatLog->entity_type,
                    'value' => $chatLog->relatedEntities
                ]);
            }

            //Mark all chats as read
            Chat::where('contact_id', $contact->id)
                ->where('type', 'inbound')
                ->whereNull('deleted_at')
                ->where('is_read', 0)
                ->update([
                    'is_read' => 1
                ]);

            if (request()->expectsJson()) {
                return response()->json([
                    'result' => ContactResource::collection($contacts)->response()->getData(),
                ], 200);
            } else {
                //To ensure the unread message counter is updated
                $unreadMessages = Chat::where('organization_id', $this->organizationId)
                    ->where('type', 'inbound')
                    ->where('deleted_at', NULL)
                    ->where('is_read', 0)
                    ->count();

                return Inertia::render('User/Chat/Index', [
                    'title' => 'Chats',
                    'rows' => ContactResource::collection($contacts),
                    'rowCount' => $rowCount,
                    'filters' => request()->all(),
                    'pusherSettings' => $pusherSettings,
                    'organizationId' => $this->organizationId,
                    'state' => app()->environment(),
                    'demoNumber' => env('DEMO_NUMBER'),
                    'settings' => $config,
                    'templates' => $messageTemplates,
                    'status' => $request->status ?? 'all',
                    'chatThread' => $chats, 
                    'contact' => $contact,
                    'fields' => ContactField::where('organization_id', $this->organizationId)->where('deleted_at', null)->get(),
                    'locationSettings' => $this->getLocationSettings(),
                    'ticket' => $ticket,
                    'agents' => $agents,
                    'addon' => $aimodule,
                    'chat_sort_direction' => $sortDirection,
                    'unreadMessages' => $unreadMessages,
                    'isChatLimitReached' => SubscriptionService::isSubscriptionFeatureLimitReached($this->organizationId, 'message_limit')
                ]);
            }
        }

        if (request()->expectsJson()) {
            return response()->json([
                'result' => ContactResource::collection($contacts)->response()->getData(),
            ], 200);
        } else {
            return Inertia::render('User/Chat/Index', [
                'title' => 'Chats',
                'rows' => ContactResource::collection($contacts),
                'rowCount' => $rowCount,
                'filters' => request()->all(),
                'pusherSettings' => $pusherSettings,
                'organizationId' => $this->organizationId,
                'state' => app()->environment(),
                'settings' => $config,
                'templates' => $messageTemplates,
                'status' => $request->status ?? 'all',
                'agents' => $agents,
                'addon' => $aimodule,
                'ticket' => array(),
                'chat_sort_direction' => $sortDirection,
                'isChatLimitReached' => SubscriptionService::isSubscriptionFeatureLimitReached($this->organizationId, 'message_limit')
            ]);
        }
    }

    public function handleTicketAssignment($contactId){
        $organizationId = $this->organizationId;
        $settings = Organization::where('id', $this->organizationId)->first();
        $settings = json_decode($settings->metadata);

        // Check if ticket functionality is active
        if(isset($settings->tickets) && $settings->tickets->active === true){
            $autoassignment = $settings->tickets->auto_assignment;
            $reassignOnReopen = $settings->tickets->reassign_reopened_chats;

            // Check if a ticket already exists for the contact
            $ticket = ChatTicket::where('contact_id', $contactId)->first();

            DB::transaction(function () use ($reassignOnReopen, $autoassignment, $ticket, $contactId, $organizationId) {
                if(!$ticket){
                    // Create a new ticket if it doesn't exist
                    $ticket = New ChatTicket;
                    $ticket->contact_id = $contactId;
                    $ticket->status = 'open';
                    $ticket->updated_at = now();

                    // Perform auto-assignment if enabled
                    if($autoassignment){
                        // Find an agent with the least number of assigned tickets
                        $agent = Team::where('organization_id', $organizationId)
                            ->withCount('tickets')
                            ->whereNull('deleted_at')
                            ->orderBy('tickets_count')->first();

                        // Assign the ticket to the agent with the least number of assigned tickets
                        $ticket->assigned_to = $agent->user_id;
                    } else {
                        $ticket->assigned_to = NULL;
                    }

                    $ticket->save();

                    $ticketId = ChatTicketLog::insertGetId([
                        'contact_id' => $contactId,
                        'description' => 'Conversation was opened',
                        'created_at' => now()
                    ]);

                    ChatLog::insert([
                        'contact_id' => $contactId,
                        'entity_type' => 'ticket',
                        'entity_id' => $ticketId,
                        'created_at' => now()
                    ]);
                } else {
                    // Reopen the ticket if it's closed and reassignment on reopen is enabled
                    if($ticket->status === 'closed'){
                        if($reassignOnReopen){
                            if($autoassignment){
                                $agent = Team::where('organization_id', $organizationId)
                                    ->withCount('tickets')
                                    ->whereNull('deleted_at')
                                    ->orderBy('tickets_count')
                                    ->first();

                                $ticket->assigned_to = $agent->user_id;
                            } else {
                                $ticket->assigned_to = NULL;
                            }
                        }

                        $ticket->status = 'open';
                        $ticket->save();

                        $ticketId = ChatTicketLog::insertGetId([
                            'contact_id' => $contactId,
                            'description' => 'Conversation was moved from closed to open',
                            'created_at' => now()
                        ]);
    
                        ChatLog::insert([
                            'contact_id' => $contactId,
                            'entity_type' => 'ticket',
                            'entity_id' => $ticketId,
                            'created_at' => now()
                        ]);
                    }
                }
            });
        }
    }

    public function sendMessage(object $request)
    {
        if($request->type === 'text'){
            return $this->whatsappService->sendMessage($request->uuid, $request->message);
        } else {
            $storage = Setting::where('key', 'storage_system')->first()->value;
            $fileName = $request->file('file')->getClientOriginalName();
            $fileContent = $request->file('file');

            if($storage === 'local'){
                $location = 'local';
                $file = Storage::disk('local')->put('public', $fileContent);
                $mediaFilePath = $file;
                $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
            } else if($storage === 'aws') {
                $location = 'amazon';
                $file = $request->file('file');
                $filePath = 'uploads/media/received/'  . $this->organizationId . '/' . $fileName;
                $uploadedFile = $file->store('uploads/media/sent/' . $this->organizationId, 's3');
                $mediaFilePath = Storage::disk('s3')->url($uploadedFile);
                $mediaUrl = $mediaFilePath;
            }
    
            $this->whatsappService->sendMedia($request->uuid, $request->type, $fileName, $mediaFilePath, $mediaUrl, $location);
        }
    }

    public function sendTemplateMessage(object $request, $uuid)
    {
        $template = Template::where('uuid', $request->template)->first();
        $contact = Contact::where('uuid', $uuid)->first();
        $mediaId = null;

        if(in_array($request->header['format'], ['IMAGE', 'DOCUMENT', 'VIDEO'])){
            $header = $request->header;
            
            if ($request->header['parameters']) {
                $metadata['header']['format'] = $header['format'];
                $metadata['header']['parameters'] = [];
        
                foreach ($request->header['parameters'] as $key => $parameter) {
                    if ($parameter['selection'] === 'upload') {
                        $storage = Setting::where('key', 'storage_system')->first()->value;
                        $fileName = $parameter['value']->getClientOriginalName();
                        $fileContent = $parameter['value'];

                        if($storage === 'local'){
                            $file = Storage::disk('local')->put('public', $fileContent);
                            $mediaFilePath = $file;
            
                            $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
                        } else if($storage === 'aws') {
                            $file = $parameter['value'];
                            $uploadedFile = $file->store('uploads/media/sent/' . $this->organizationId, 's3');
                            $mediaFilePath = Storage::disk('s3')->url($uploadedFile);
            
                            $mediaUrl = $mediaFilePath;
                        }

                        $contentType = $this->getContentTypeFromUrl($mediaUrl);
                        $mediaSize = $this->getMediaSizeInBytesFromUrl($mediaUrl);

                        //save media
                        $chatMedia = new ChatMedia;
                        $chatMedia->name = $fileName;
                        $chatMedia->location = $storage == 'aws' ? 'amazon' : 'local';
                        $chatMedia->path = $mediaUrl;
                        $chatMedia->type = $contentType;
                        $chatMedia->size = $mediaSize;
                        $chatMedia->created_at = now();
                        $chatMedia->save();

                        $mediaId = $chatMedia->id;
                    } else {
                        $mediaUrl = $parameter['value'];
                    }
        
                    $metadata['header']['parameters'][] = [
                        'type' => $parameter['type'],
                        'selection' => $parameter['selection'],
                        'value' => $mediaUrl,
                    ];
                }
            }
        } else {
            $metadata['header'] = $request->header;
        }

        $metadata['body'] = $request->body;
        $metadata['footer'] = $request->footer;
        $metadata['buttons'] = $request->buttons;
        $metadata['media'] = $mediaId;

        //Build Template to send
        $template = $this->buildTemplate($template->name, $template->language, json_decode(json_encode($metadata)), $contact);
        
        return $this->whatsappService->sendTemplateMessage($contact->uuid, $template, NULL, $mediaId);
    }

    public function clearMessage($uuid)
    {
        Chat::where('uuid', $uuid)
            ->update([
                'deleted_by' => auth()->user()->id,
                'deleted_at' => now()
            ]);
    }

    public function clearContactChat($uuid)
    {
        $contact = Contact::with('lastChat')->where('uuid', $uuid)->firstOrFail();
        Chat::where('contact_id', $contact->id)->update([
            'deleted_by' => auth()->user()->id,
            'deleted_at' => now()
        ]);

        ChatLog::where('contact_id', $contact->id)->where('entity_type', 'chat')->update([
            'deleted_by' => auth()->user()->id,
            'deleted_at' => now()
        ]);

        $chat = Chat::with('contact','media')->where('id', $contact->lastChat->id)->first();

        //event(new NewChatEvent($chat, $contact->organization_id));
    }

    private function getContentTypeFromUrl($url) {
        try {
            // Make a HEAD request to fetch headers only
            $response = Http::head($url);
    
            // Check if the Content-Type header is present
            if ($response->hasHeader('Content-Type')) {
                return $response->header('Content-Type');
            }
    
            return null;
        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error('Error fetching headers: ' . $e->getMessage());
            return null;
        }
    }

    private function getMediaSizeInBytesFromUrl($url) {
        $url = ltrim($url, '/');
        $imageContent = file_get_contents($url);
    
        if ($imageContent !== false) {
            return strlen($imageContent);
        }
    
        return null;
    }

    private function getLocationSettings(){
        // Retrieve the settings for the current organization
        $settings = Organization::where('id', $this->organizationId)->first();

        if ($settings) {
            // Decode the JSON metadata column into an associative array
            $metadata = json_decode($settings->metadata, true);

            if (isset($metadata['contacts'])) {
                // If the 'contacts' key exists, retrieve the 'location' value
                $location = $metadata['contacts']['location'];

                // Now, you have the location value available
                return $location;
            } else {
                return null;
            }
        } else {
            return null;
        }
    }
}