<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreOrganization;
use App\Services\OrganizationService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OrganizationController extends BaseController
{
    private $organizationService;
    private $role;

    /**
     * OrganizationController constructor.
     *
     * @param UserService $organizationService
     */
    public function __construct()
    {
        $this->organizationService = new OrganizationService();
    }

    /**
     * Display a listing of organizations.
     *
     * @param Request $request
     * @return \Inertia\Response
     */
    public function index(Request $request)
    {
        return Inertia::render('Admin/Organization/Index', [
            'title' => __('Organizations'),
            'allowCreate' => true,
            'rows' => $this->organizationService->get($request), 
            'filters' => $request->all()
        ]);
    }

    /**
     * Display the specified organization.
     *
     * @param string $uuid
     * @return \Inertia\Response
     */
    public function show(Request $request, $uuid = NULL, $mode = NULL)
    {
        $res = $this->organizationService->getByUuid($request, $uuid);
        return Inertia::render('Admin/Organization/Show', [
            'title' => __('Organization'),
            'organization' => $res['organization'], 
            'users' => $res['users'],
            'plans' => $res['plans'], 
            'invoices' => $res['billing'],
            'mode' => $mode,
            'filters' => $request->all()
        ]);
    }

    /**
     * Display Form
     *
     * @param $request
     */
    public function create(Request $request)
    {
        $res = $this->organizationService->getByUuid($request);
        return Inertia::render('Admin/Organization/Show', [
            'title' => __('Create Org.'),
            'organization' => $res['organization'], 
            'users' => $res['users'],
            'plans' => $res['plans'], 
            'invoices' => $res['billing'],
            'filters' => $request->all()
        ]);
    }

    /**
     * Store a newly created organization.
     *
     * @param Request $request
     */
    public function store(StoreOrganization $request)
    {
        $this->organizationService->store($request);

        return redirect('/admin/organizations')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Organization created successfully!')
            ]
        );
    }

    /**
     * Update the specified organization.
     *
     * @param Request $request
     */
    public function update(StoreOrganization $request, $uuid)
    {
        $this->organizationService->update($request, $uuid);

        return redirect('/admin/organizations/'.$uuid)->with(
            'status', [
                'type' => 'success', 
                'message' => __('Organization updated successfully!')
            ]
        );
    }

    /**
     * Remove the specified organization.
     *
     * @param String $uuid
     */
    public function destroy($uuid)
    {
        $query = $this->organizationService->destroy($uuid);

        return back()->with(
            'status', [
                'type' => $query ? 'success' : 'error', 
                'message' => $query ? __('Organization deleted successfully!') : __('This organization does not exist!')
            ]
        );
    }
}