<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Order\OrderStateMachine;
use App\Exceptions\IdempotencyException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CancelOrderRequest;
use App\Http\Requests\Order\OrderIndexRequest;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Services\Idempotency\IdempotencyService;
use App\Services\InvoiceRenderer;
use App\Services\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class OrderController extends Controller
{
    private const RELATIONS = ['customer', 'creator', 'items', 'histories.actor'];

    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderStateMachine $stateMachine,
        private readonly IdempotencyService $idempotency,
        private readonly InvoiceRenderer $invoices,
    ) {
    }

    /**
     * List orders. Filters: status, customer_id, date_from, date_to (business-day dates), search (order number).
     */
    public function index(OrderIndexRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Order::class);
        [$column, $direction] = $request->sort();
        $like = $request->likeTerm();
        $tz = (string) config('shop.business_timezone');
        $status = $request->validated('status');
        $customerId = $request->validated('customer_id');
        $from = $request->validated('date_from');
        $to = $request->validated('date_to');

        $paginator = Order::query()
            ->with(['customer', 'creator'])
            ->withCount('items')
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->when($customerId !== null, fn (Builder $q) => $q->where('customer_id', $customerId))
            ->when($from !== null, fn (Builder $q) => $q->where('order_date', '>=', CarbonImmutable::parse((string) $from, $tz)->startOfDay()->utc()))
            ->when($to !== null, fn (Builder $q) => $q->where('order_date', '<', CarbonImmutable::parse((string) $to, $tz)->addDay()->startOfDay()->utc()))
            ->when($like !== null, fn (Builder $q) => $q->where('order_number', 'ilike', $like))
            ->orderBy($column, $direction)
            ->orderByDesc('id')
            ->paginate($request->perPage(), ['*'], 'page', $request->page());

        return ApiResponse::paginated($paginator, OrderResource::class);
    }

    /**
     * Create an order (stock is reserved immediately, status PENDING).
     *
     * Requires header `Idempotency-Key: <uuid>`. Replays of the same key + payload return the
     * stored response with header `Idempotent-Replayed: true`; same key + different payload → 422;
     * same key while the first request is still running → 409 with Retry-After.
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (! Str::isUuid($key)) {
            throw IdempotencyException::missingOrInvalid();
        }

        $actor = $this->actor($request);

        $result = $this->idempotency->execute(
            $actor->id,
            'orders.create',
            mb_strtolower($key),
            $request->fingerprint(),
            function () use ($request, $actor): array {
                $order = $this->orders->create($actor, (string) $request->validated('customer_id'), $request->items());

                return [
                    'status' => 201,
                    'body' => ApiResponse::body(new OrderResource($order), 'Order berhasil dibuat'),
                ];
            },
        );

        return new JsonResponse($result->body, $result->status, [
            'Idempotent-Replayed' => $result->replayed ? 'true' : 'false',
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        Gate::authorize('view', $order);

        return ApiResponse::success(new OrderResource($order->load(self::RELATIONS)));
    }

    public function pay(Request $request, Order $order): JsonResponse
    {
        Gate::authorize('pay', $order);
        $order = $this->stateMachine->pay($order, $this->actor($request));

        return ApiResponse::success(new OrderResource($order), 'Order ditandai PAID');
    }

    public function ship(Request $request, Order $order): JsonResponse
    {
        Gate::authorize('ship', $order);
        $order = $this->stateMachine->ship($order, $this->actor($request));

        return ApiResponse::success(new OrderResource($order), 'Order ditandai SHIPPED');
    }

    public function complete(Request $request, Order $order): JsonResponse
    {
        Gate::authorize('complete', $order);
        $order = $this->stateMachine->complete($order, $this->actor($request));

        return ApiResponse::success(new OrderResource($order), 'Order ditandai COMPLETED');
    }

    public function cancel(CancelOrderRequest $request, Order $order): JsonResponse
    {
        $order = $this->stateMachine->cancel($order, $this->actor($request), (string) $request->validated('cancel_reason'));

        return ApiResponse::success(new OrderResource($order), 'Order dibatalkan, stok dikembalikan');
    }

    /**
     * Download the HTML invoice (PAID / SHIPPED / COMPLETED orders only, 409 otherwise).
     */
    public function invoice(Order $order): Response
    {
        Gate::authorize('downloadInvoice', $order);
        $html = $this->invoices->render($order);

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$this->invoices->filename($order).'"',
        ]);
    }
}
