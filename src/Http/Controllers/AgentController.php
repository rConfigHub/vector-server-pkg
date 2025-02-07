<?php

namespace Rconfig\VectorServer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\RespondsWithHttpStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Rconfig\VectorServer\Http\Requests\StoreAgentRequest;
use Rconfig\VectorServer\Models\Agent;
use Spatie\QueryBuilder\QueryBuilder;

class AgentController extends Controller
{
    use RespondsWithHttpStatus;

    public function index(Request $request)
    {
        $this->authorize('agent.view');

        $userRole = auth()->user()->roles()->first();

        $searchCols = ['name', 'email'];
        $query = QueryBuilder::for(Agent::class)
            ->allowedFilters($searchCols)
            ->defaultSort('-id')
            ->allowedSorts('name', 'id', 'created_at')
            ->allowedIncludes(['roles'])
            ->with('roles')
            ->filterByRole($userRole->id)
            ->paginate($request->perPage ?? 10);
        return response()->json($query);
    }

    public function store(StoreAgentRequest $request)
    {
        $this->authorize('agent.create');

        $roles = array_column($request->roles, 'id');
        unset($request['roles']);

        $model = Agent::create($request->toDTO()->toArray());
        $model->roles()->sync($roles);

        return $this->successResponse('Agent created successfully!', ['id' => $model->id]);
    }

    public function show($id, $relationship = null, $withCount = null)
    {
        $this->authorize('agent.view');

        $userRole = auth()->user()->roles()->first();

        $query = Agent::query();

        $query->findOrFail($id);

        if ($relationship != null) {
            foreach ($relationship as $rel) {
                $query->with($rel);
            }
        }

        $result = $query->filterByRole($userRole->id)->with('roles')->first();

        return response()->json($result);
    }

    public function update($id, StoreAgentRequest $request)
    {

        $this->authorize('agent.update');

        $roles = $request->roles instanceof \Illuminate\Support\Collection ? $request->roles->pluck('id')->toArray() : array_column($request->roles, 'id');
        unset($request['roles']);

        $model = Agent::find($id);
        $model = tap($model)->update($request->toDTO()->toArray()); // tap returns model instead of boolean
        $model->roles()->sync($roles);

        if (! $model->roles()->find(1)) {
            $model->roles()->attach([1]);
        }

        return $this->successResponse('Agent edited successfully!', ['id' => $model->id]);
    }

    public function destroy($id, $return = 0)
    {
        $this->authorize('agent.delete');

        if ($id == 1) {
            return $this->failureResponse('You cannot delete the Vector agent!', 422);
        }

        $model = Agent::find($id);
        $model = tap($model)->delete(); // tap returns model instead of boolean
        $model->roles()->detach();

        return $this->successResponse('Agent deleted successfully!', ['id' => $model->id]);
    }

    public function regenerateToken($id)
    {
        $this->authorize('agent.update');

        $model = Agent::findOrFail($id);
        $model->api_token = Str::uuid()->toString();
        $model->save();

        return $this->successResponse('Token regenerated successfully!', ['id' => $model->api_token]);
    }
}
