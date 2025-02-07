<?php

namespace App\Http\Controllers\User;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreTeam;
use App\Http\Resources\TeamResource;
use App\Models\Team;
use App\Services\TeamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use App\Helpers\CustomHelper;

class TeamController extends BaseController
{
    private $teamService;

    public function __construct(TeamService $teamService)
    {
        $this->teamService = $teamService;
    }

    public function index(Request $request)
    {
        if (!CustomHelper::checkTeamUserPermission('team')) {
            abort(404);
        }
        $rows = TeamResource::collection(
            Team::with('user')->where('organization_id', session()->get('current_organization'))
                ->latest()->paginate(10)
        );

        if ($request->expectsJson()) {
            $rows = DB::table('users')
                ->join('teams', 'users.id', '=', 'teams.user_id')
                ->where('teams.organization_id', '=', session()->get('current_organization'))
                ->whereNull('teams.deleted_at')
                ->select('users.*')
                ->get();

            return response()->json([
                'rows' => $rows
            ]);
        } else {
            return Inertia::render('User/Team/Index', [
                'title' => __('Team'),
                'filters' => $request->all(),
                'rows' => $rows,
            ]);
        }
    }

    public function invite(StoreTeam $request)
    {
        $this->teamService->invite($request);

        //response()->json(['success' => true, 'message'=> __('User invited successfully!'), 'data' => $invite])

        return Redirect::back()->with(
            'status',
            [
                'type' => 'success',
                'message' => __('User invited successfully!')
            ]
        );
    }

    public function update(Request $request, $uuid)
    {
        $this->teamService->update($request, $uuid);

        return Redirect::back()->with(
            'status',
            [
                'type' => 'success',
                'message' => __('User account updated successfully!')
            ]
        );
    }

    public function delete($uuid)
    {
        $this->teamService->destroy($uuid);
    }
}
