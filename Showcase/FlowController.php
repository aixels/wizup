<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Addon;
use App\Models\AdminFlow;
use App\Models\Setting;
use App\Services\FlowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use App\Models\Organization;
use App\Services\SubscriptionPlanService;
class FlowController extends BaseController
{
    
    protected $flowService;
    private $SubscriptionPlanService;

    public function __construct(FlowService $flowService, SubscriptionPlanService $subscriptionPlanService)
    {
        $this->flowService = $flowService;
        $this->subscriptionPlanService = $subscriptionPlanService;
    }

    /**
     * Display a listing of flows.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $data['title'] = __('Settings');

        $aiAssistantAddon = Addon::where('name', 'AI Assistant')->first();
        $aiAssistantSetting = Setting::where('key', 'ai_assistant')->first();
        $flowBuilderAddon = Addon::where('name', 'Flow builder')->first();
        $flowBuilderSetting = Setting::where('key', 'flow_builder')->first();

        $data['aimodule'] = $aiAssistantAddon && $aiAssistantAddon->status && $aiAssistantSetting && $aiAssistantSetting->value == 1;
        $data['fbmodule'] = $flowBuilderAddon && $flowBuilderAddon->status && $flowBuilderSetting && $flowBuilderSetting->value == 1;
        $data['rows'] = $this->flowService->getRows($request);
        $data['filters'] = request()->all();
        return Inertia::render('Admin/FlowBuilder/Index', $data);
    }

    public function view(Request $request, $uuid)
    {
        $data['uuid'] = $uuid;
        $data['flow'] = AdminFlow::where('uuid', $uuid)->firstOrFail();
        $data['plans'] = $this->subscriptionPlanService->get($request);

        return Inertia::render('Admin/FlowBuilder/View', $data);
    }

    /**
     * Store a newly created flow in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'plans_id' => 'required|array',
            'image' => 'nullable|image',
        ]);
        
        $data = [
            'name' => $data['name'] ?? $flow->name,
            'description' => $data['description'],
            'plans_id' => $data['plans_id'],
        ];

        $flow = $this->flowService->createFlow($data);

        if ($request->hasFile('image') && $request->file('image')->isValid()) {
            // Run the upload function
            $data['image_path'] = $this->flowService->uploadFlowMedia($flow->uuid, $request);
        }

        return redirect('/admin/automation/flows/'.$flow->uuid)->with(
            'status', [
                'type' => 'success', 
                'message' => __('Flow automation created successfully!')
            ]
        );
    }

    public function update(Request $request, $uuid)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'plans_id' => 'array',
            'metadata' => 'nullable|json' 
        ]);

        $flow = AdminFlow::where('uuid', $uuid)->firstOrFail();
        $data = [
            'name' => $data['name'] ?? $flow->name,
            'description' => $data['description'] ?? $flow->description,
            'plans_id' => $data['plans_id'] ?? $flow->plans_id,
            'metadata' => $data['metadata'] ?? $flow->metadata,
        ];

        $flow = $this->flowService->updateFlow($uuid, $data);
        if ($request->hasFile('image') && $request->file('image')->isValid()) {
            // Run the upload function
            $data['image_path'] = $this->flowService->uploadFlowMedia($uuid, $request);
        }
        // Check if the request expects JSON response
        if (request()->expectsJson()) {
            return response()->json($flow);
        }

        // If it's an Inertia request, redirect back
        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Flow automation updated successfully!')
            ]
        );
    }

    public function uploadMedia(Request $request, $uuid, $stepId)
    {
        $flow = $this->flowService->uploadMedia($request, $uuid, $stepId);

        return response()->json($flow);
    }

    /**
     * Publish the specified flow.
     *
     * @param Flow $flow
     * @return \Illuminate\Http\Response
     */
    public function publish($uuid)
    {
        $flow = $this->flowService->publishFlow($uuid);
        return response()->json($flow);
    }

    /**
     * Deactivate the specified flow.
     *
     * @param Flow $flow
     * @return \Illuminate\Http\Response
     */
    public function unpublish($uuid)
    {
        $flow = $this->flowService->deactivateFlow($uuid);
        return response()->json($flow);
    }

    /**
     * Remove the specified flow from storage.
     *
     * @param Flow $flow
     * @return \Illuminate\Http\Response
     */
    public function destroy($uuid)
    {
        $this->flowService->deleteFlow($uuid);
        
        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Flow automation deleted successfully!')
            ]
        );
    }

    public function getAdminFlows()
    {
        $organization = Organization::where('id', session('current_organization'))->with('subscription')->first();
        $flows = AdminFlow::where('deleted_at', null)
            ->where('status', 'active')
            ->latest()
            ->paginate(10);
        $data = [
            'flows' => $flows,
            'plan_id' => $organization->subscription->plan_id
        ];
        return response()->json([
            'statusCode' => 200,
            'data' => $flows,
            'plan_id' => $organization->subscription->plan_id

        ], 200);
    }
   
}
