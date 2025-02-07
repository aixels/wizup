<?php

namespace App\Http\Controllers\User;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\PaymentRequest;
use App\Models\Addon;
use App\Models\BillingPayment;
use App\Models\Organization;
use App\Models\PaymentGateway;
use App\Models\Setting;
use App\Models\Subscription;
use App\Resolvers\PaymentPlatformResolver;
use App\Services\BillingService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Redirect;
use App\Helpers\CustomHelper;
class BillingController extends BaseController
{
    protected $billingService;
    protected $subscriptionService;
    protected $paymentPlatformResolver;

    public function __construct()
    {
        $this->billingService = new BillingService();
        $this->subscriptionService = new SubscriptionService();
        $this->paymentPlatformResolver = new PaymentPlatformResolver();
    }
    
    public function index(Request $request){
        if (!CustomHelper::checkTeamUserPermission('billing')) {
            abort(404);
        }
        $organizationId = session()->get('current_organization');
        $organization = Organization::where('id', $organizationId)->first();
        $data['subscription'] = Subscription::with('plan')->where('organization_id', $organizationId)->first();
        $data['subscriptionIsActive'] = SubscriptionService::isSubscriptionActive($organizationId);
        $data['rows'] = $this->billingService->get($request, $organization->uuid);
        $data['filters'] = $request->all();
        $data['methods'] = $this->paymentMethods();
        $data['subscriptionDetails'] = SubscriptionService::calculateSubscriptionBillingDetails($organizationId, $data['subscription']->plan_id);
        $data['title'] = __('Billing');
        $data['isPaymentLoading'] = false;
        $data['pusherSettings'] = Setting::whereIn('key', [
            'pusher_app_id',
            'pusher_app_key',
            'pusher_app_secret',
            'pusher_app_cluster',
        ])->pluck('value', 'key')->toArray();
        $data['setting'] = Setting::whereIn('key', ['enable_custom_payment'])->pluck('value', 'key')->toArray();
        $data['organizationId'] = $organizationId;

        if($request->has('paymentId') && $request->has('token')){
            //Check if payment id exists in DB
            $payment = BillingPayment::where('details', $request->paymentId)->first();
            if(!$payment){
                $data['isPaymentLoading'] = true;
            } else {
                return redirect('/billing')->with(
                    'status', [
                        'type' => 'success', 
                        'message' => __('Payment processed successfully!')
                    ]
                );
            }
        }

        return Inertia::render('User/Billing/Index', $data);
    }

    public function pay(PaymentRequest $request){
        $paymentPlatform = $this->paymentPlatformResolver->resolveService($request->method);
        session()->put('paymentPlatform', $request->method);

        $response = $paymentPlatform->handlePayment($request->amount);

        if ($response->success === true) {
            return inertia::location($response->data);
        } else {
            return redirect('/billing')->with(
                'status', [
                    'type' => 'error', 
                    'message' => __('Could not process your payment successfully!')
                ]
            );
        }
    }

    private function paymentMethods(){
        $mergedData = [];

        // Retrieve active payment methods and add to mergedData
        $paymentMethods = PaymentGateway::where('is_active', 1)->get();
        $mergedData = $paymentMethods->map(function ($method) {
            return ['name' => $method->name];
        })->toArray();

        // Retrieve active addons and check settings
        $activeAddons = Addon::where('category', 'payments')
            ->where('status', 1)
            ->get()
            ->filter(function ($addon) {
                $addonName = strtolower($addon->name);
                $settingKey = "{$addonName}_active";
                return Setting::where('key', $settingKey)->where('value', 1)->exists();
            })
            ->pluck('name')
            ->toArray();

        // Add active addons to mergedData
        foreach ($activeAddons as $addonName) {
            $mergedData[] = ['name' => $addonName];
        }

        return $mergedData;
    }
}