<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Models\AutoReply;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Services\ChatService;
use App\Services\WhatsappService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Redirect;
use App\Helpers\CustomHelper;

class ChatController extends BaseController
{
    private function chatService()
    {
        return new ChatService(session()->get('current_organization'));
    }

    public function index(Request $request, $uuid = null)
    {
        if (!CustomHelper::checkTeamUserPermission('chats')) {
            abort(404);
        }
        return $this->chatService()->getChatList($request, $uuid, $request->query('search'));
    }
    public function sendTemplateMessage(Request $request, $uuid)
    {
        if (!CustomHelper::checkTeamUserPermission('chats')) {
            abort(404);
        }
        $this->chatService()->sendTemplateMessage($request, $uuid);
        return Redirect::back()->with(
            'status',
            [
                'type' => 'success',
                'message' => __('Template Message send!')
            ]
        );
    }

    public function updateChatSortDirection(Request $request)
    {
        if (!CustomHelper::checkTeamUserPermission('chats')) {
            abort(404);
        }
        $request->session()->put('chat_sort_direction', $request->sort);

        return Redirect::back();
    }

    public function sendMessage(Request $request)
    {
        if (!CustomHelper::checkTeamUserPermission('chats')) {
            abort(404);
        }
        return $this->chatService()->sendMessage($request);
    }

    public function deleteChats($uuid)
    {
        if (!CustomHelper::checkTeamUserPermission('chats')) {
            abort(404);
        }
        $this->chatService()->clearContactChat($uuid);

        return Redirect::back()->with(
            'status',
            [
                'type' => 'success',
                'message' => __('Chat cleared successfully!')
            ]
        );
    }
}
