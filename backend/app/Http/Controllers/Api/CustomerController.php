<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CustomerIndexRequest;
use App\Http\Requests\Customer\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Services\DashboardService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Plain CRUD with no cross-entity invariants, so no dedicated service class.
 */
final class CustomerController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    public function index(CustomerIndexRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Customer::class);
        [$column, $direction] = $request->sort();
        $like = $request->likeTerm();

        $paginator = Customer::query()
            ->when($like !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('customer_name', 'ilike', $like)
                ->orWhere('phone', 'ilike', $like)
                ->orWhere('email', 'ilike', $like)))
            ->orderBy($column, $direction)
            ->orderBy('id')
            ->paginate($request->perPage(), ['*'], 'page', $request->page());

        return ApiResponse::paginated($paginator, CustomerResource::class);
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        $customer = Customer::query()->create($request->validated());
        $this->dashboard->invalidate();

        return ApiResponse::success(new CustomerResource($customer->refresh()), 'Pelanggan berhasil dibuat', 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        Gate::authorize('view', $customer);

        return ApiResponse::success(new CustomerResource($customer));
    }

    public function update(CustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer->fill($request->validated())->save();

        return ApiResponse::success(new CustomerResource($customer), 'Pelanggan berhasil diperbarui');
    }

    public function destroy(Customer $customer): JsonResponse
    {
        Gate::authorize('delete', $customer);
        $customer->delete();
        $this->dashboard->invalidate();

        return ApiResponse::noContent();
    }
}
