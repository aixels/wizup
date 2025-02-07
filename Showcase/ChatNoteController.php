<?php

namespace App\Http\Controllers\User;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreChatNote;
use App\Models\Contact;
use App\Services\ChatNoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule; 
use Inertia\Inertia;
use Helper;
use Redirect;
use Session;
use Validator;

class ChatNoteController extends BaseController
{
    public function __construct(ChatNoteService $chatNoteService)
    {
        $this->chatNoteService = $chatNoteService;
    }

    public function store(StoreChatNote $request)
    {
        $this->chatNoteService->store($request);
        $contact = Contact::with(['lastChat', 'lastInboundChat', 'notes'])->where('uuid', $request->contact)->first();

        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Note added successfully!'),
                'contact' => $contact,
            ]
        );
    }

    public function show($id)
    {
        //
    }

    public function update(StoreFaq $request, $id)
    {
        //
    }

    public function destroy($uuid)
    {
        $this->chatNoteService->delete($uuid);

        return redirect('/admin/faqs')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Note deleted successfully!')
            ]
        );
    }
    public function destroyUser($uuid)
    {
        $this->chatNoteService->delete($uuid);
        return Redirect::back()->with(
            'status',
            [
                'type' => 'success', 
                'message' => __('Note deleted successfully!')
            ]
        );
    }
}