<?php

namespace App\Http\Controllers\User;

use Carbon\Carbon;
use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Helpers\CustomHelper;
use App\Helpers\SubscriptionHelper;
use App\Models\Addon;
use App\Models\Chat;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Template;
use App\Services\SubscriptionService;
use App\Services\WhatsappService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends BaseController
{
    public function __construct()
    {
        $this->subscriptionService = new SubscriptionService();
    }

    public function index(Request $request){
        $organizationId = session()->get('current_organization');
        $data['subscription'] = Subscription::with('plan')->where('organization_id', $organizationId)->first();
        $data['subscriptionDetails'] = SubscriptionService::calculateSubscriptionBillingDetails($organizationId, $data['subscription']->plan_id);
        $data['subscriptionIsActive'] = SubscriptionService::isSubscriptionActive($organizationId);
        $data['chatCount'] = Chat::where('organization_id', $organizationId)->whereNull('deleted_at')->count();
        $data['campaignCount'] = Campaign::where('organization_id', $organizationId)->count();
        $data['contactCount'] = Contact::where('organization_id', $organizationId)->whereNull('deleted_at')->count();
        $data['templateCount'] = Template::where('organization_id', $organizationId)->whereNull('deleted_at')->count();
        $data['graphAPIVersion'] = config('graph.api_version');

        $organizationId = session()->get('current_organization');
        $organization = Organization::where('id', $organizationId)->first();
        $config = $organization->metadata ? json_decode($organization->metadata, true) : [];
        $settings = Setting::whereIn('key', ['is_embedded_signup_active', 'whatsapp_client_id', 'whatsapp_config_id'])
            ->pluck('value', 'key');

        $data['organization'] = $organization;
        
        $data['campaigns'] = Campaign::where('organization_id', $organizationId)
            ->whereIn('status', ['pending', 'scheduled'])
            ->limit(5)
            ->get();
        $data['setupWhatsapp'] = isset($config['whatsapp']) ? false : true;;
        $data['period'] = $this->period();
        $data['inbound'] = $this->getChatCounts('inbound');
        $data['outbound'] = $this->getChatCounts('outbound');
        $data['embeddedSignupActive'] = CustomHelper::isModuleEnabled('Embedded Signup');
        $data['appId'] = $settings->get('whatsapp_client_id', null);
        $data['configId'] = $settings->get('whatsapp_config_id', null);
        $data['title'] = __('Dashboard');

        $data['role'] = session()->get('current_user_role');
        $data['permissions'] = session()->get('current_user_permission');

        return Inertia::render('User/Dashboard', $data);
    }

    public function dismissNotification(Request $request, $type){
        $currentOrganizationId = session()->get('current_organization');
        $organizationConfig = Organization::where('id', $currentOrganizationId)->first();

        $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];

        if($type === 'team'){
            $metadataArray['notification']['team'] = false;
        }

        $updatedMetadataJson = json_encode($metadataArray);

        $organizationConfig->metadata = $updatedMetadataJson;
        $organizationConfig->save();

        return redirect()->route('dashboard')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Notification dismissed successfully!')
            ]
        );
    }

    private function period(){
        $currentDate = Carbon::now();
        $dateArray = [];

        for ($i = 0; $i < 7; $i++) {
            $currentDate->startOfDay();
            $dateArray[] = $currentDate->format('Y-m-d\TH:i:s.000\Z');
            $currentDate->subDay();
        }

        $dateArray = array_reverse($dateArray);

        return $dateArray;
    }

    private function getChatCounts($type){
        $organizationId = session()->get('current_organization');
        $chatCounts = [];

        foreach ($this->period() as $dateString) {
            $date = Carbon::parse($dateString);
            $chatCount = Chat::where('organization_id', $organizationId)
                ->where('type', $type)
                ->whereNull('deleted_at')
                ->whereDate('created_at', $date->toDateString())
                ->count();
            $chatCounts[] = $chatCount;
        }

        return $chatCounts;
    }
}