<?php

namespace App\Http\Controllers\Livreo;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class RmaController extends LivreoBaseController
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(5, min(50, (int) $request->integer('per_page', 20)));
        $search = trim((string) $request->string('q', ''));
        $status = $request->string('status')->toString();

        $query = $this->ecommerce()
            ->table('rma_requests')
            ->join('orders', 'orders.id', '=', 'rma_requests.order_id')
            ->leftJoin('users', 'users.id', '=', 'rma_requests.user_id')
            ->select([
                'rma_requests.id',
                'rma_requests.rma_number',
                'rma_requests.status',
                'rma_requests.reason',
                'rma_requests.created_at',
                'orders.number as order_number',
                'orders.id as order_id',
                'users.email as user_email',
            ])
            ->selectSub(
                $this->ecommerce()
                    ->table('rma_comments')
                    ->selectRaw('count(*)')
                    ->whereColumn('rma_comments.rma_request_id', 'rma_requests.id'),
                'comments_count',
            )
            ->selectSub(
                $this->ecommerce()
                    ->table('rma_attachments')
                    ->selectRaw('count(*)')
                    ->whereColumn('rma_attachments.rma_request_id', 'rma_requests.id'),
                'attachments_count',
            )
            ->orderByDesc('rma_requests.created_at');

        if ($status !== '') {
            $query->where('rma_requests.status', $status);
        }

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('rma_requests.rma_number', 'like', $like)
                    ->orWhere('orders.number', 'like', $like)
                    ->orWhere('users.email', 'like', $like);
            });
        }

        $page = $query->paginate($perPage);

        $tickets = collect($page->items())->map(function ($row) {
            return [
                'id' => (int) $row->id,
                'rma_number' => (string) $row->rma_number,
                'status' => (string) $row->status,
                'reason' => (string) $row->reason,
                'created_at' => (string) $row->created_at,
                'order' => [
                    'id' => (int) $row->order_id,
                    'number' => (string) $row->order_number,
                ],
                'customer_email' => $row->user_email ? (string) $row->user_email : null,
                'comments_count' => (int) $row->comments_count,
                'attachments_count' => (int) $row->attachments_count,
            ];
        })->values();

        return response()->json([
            'data' => $tickets,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $ticket = $this->ecommerce()
            ->table('rma_requests')
            ->where('id', $id)
            ->first();

        if (! $ticket) {
            abort(404);
        }

        $order = $this->ecommerce()
            ->table('orders')
            ->where('id', $ticket->order_id)
            ->select(['id', 'number', 'status', 'payment_status', 'placed_at', 'metadata'])
            ->first();

        $metadata = $this->decodeJson($ticket->metadata);

        $items = $this->ecommerce()
            ->table('rma_items')
            ->leftJoin('order_items', 'order_items.id', '=', 'rma_items.order_item_id')
            ->where('rma_items.rma_request_id', $id)
            ->select([
                'rma_items.id',
                'rma_items.quantity',
                'rma_items.evaluation_status',
                'rma_items.notes',
                'order_items.id as order_item_id',
                'order_items.name as order_item_name',
                'order_items.reference as order_item_reference',
            ])
            ->orderBy('rma_items.id')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => (int) $item->id,
                    'quantity' => (int) $item->quantity,
                    'evaluation_status' => (string) $item->evaluation_status,
                    'notes' => $item->notes ? (string) $item->notes : null,
                    'order_item' => [
                        'id' => $item->order_item_id ? (int) $item->order_item_id : null,
                        'name' => $item->order_item_name ? (string) $item->order_item_name : null,
                        'reference' => $item->order_item_reference ? (string) $item->order_item_reference : null,
                    ],
                ];
            })->values();

        $comments = $this->ecommerce()
            ->table('rma_comments')
            ->leftJoin('users', 'users.id', '=', 'rma_comments.user_id')
            ->where('rma_comments.rma_request_id', $id)
            ->select([
                'rma_comments.id',
                'rma_comments.comment',
                'rma_comments.is_internal',
                'rma_comments.created_at',
                'users.email as author_email',
            ])
            ->orderBy('rma_comments.created_at')
            ->get()
            ->map(function ($comment) {
                return [
                    'id' => (int) $comment->id,
                    'comment' => (string) $comment->comment,
                    'is_internal' => (bool) $comment->is_internal,
                    'created_at' => (string) $comment->created_at,
                    'author_email' => $comment->author_email ? (string) $comment->author_email : null,
                ];
            })->values();

        $attachments = $this->ecommerce()
            ->table('rma_attachments')
            ->where('rma_request_id', $id)
            ->select(['id', 'path', 'type', 'created_at'])
            ->orderBy('id')
            ->get()
            ->map(function ($att) {
                return [
                    'id' => (int) $att->id,
                    'path' => (string) $att->path,
                    'type' => (string) $att->type,
                    'created_at' => (string) $att->created_at,
                ];
            })->values();

        $shopBase = rtrim((string) config('livreo.shop_base_url'), '/');
        $orderNumber = $order?->number ? (string) $order->number : '';

        return response()->json([
            'ticket' => [
                'id' => (int) $ticket->id,
                'rma_number' => (string) $ticket->rma_number,
                'status' => (string) $ticket->status,
                'reason' => (string) $ticket->reason,
                'description' => $ticket->description ? (string) $ticket->description : null,
                'created_at' => (string) $ticket->created_at,
                'metadata' => $metadata,
                'order' => $order ? [
                    'id' => (int) $order->id,
                    'number' => (string) $order->number,
                    'status' => (string) $order->status,
                    'payment_status' => (string) $order->payment_status,
                    'placed_at' => (string) $order->placed_at,
                    'shipping_choice' => Arr::get($this->decodeJson($order->metadata), 'shipping'),
                ] : null,
                'shop_links' => [
                    'admin' => $shopBase.'/admin/sav-rma/'.$ticket->id,
                    'customer' => $shopBase.'/mon-compte/sav/'.$ticket->id,
                    'order_admin' => $orderNumber !== '' ? $shopBase.'/admin/commandes/'.$orderNumber : null,
                ],
            ],
            'items' => $items,
            'comments' => $comments,
            'attachments' => $attachments,
        ]);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:received,in_review,accepted,refused,refunded,replaced'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'comment_visibility' => ['nullable', 'in:internal,customer'],
        ]);

        $ticket = $this->ecommerce()->table('rma_requests')->where('id', $id)->first();
        if (! $ticket) {
            abort(404);
        }

        $this->ecommerce()->table('rma_requests')->where('id', $id)->update([
            'status' => $data['status'],
            'updated_at' => now(),
        ]);

        $commentId = null;
        if (! empty($data['comment'])) {
            $isInternal = ($data['comment_visibility'] ?? 'internal') === 'internal';
            $commentId = $this->ecommerce()->table('rma_comments')->insertGetId([
                'rma_request_id' => $id,
                'user_id' => null,
                'comment' => $data['comment'],
                'is_internal' => $isInternal,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->safeAudit([
            'actor_user_id' => $request->user()?->id,
            'entity_type' => 'rma',
            'entity_id' => (string) $id,
            'action' => 'status_updated',
            'payload' => [
                'status' => $data['status'],
                'comment_id' => $commentId,
            ],
        ]);

        return response()->json(['status' => 'ok']);
    }

    public function comment(int $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
            'visibility' => ['nullable', 'in:internal,customer'],
        ]);

        $ticketExists = $this->ecommerce()->table('rma_requests')->where('id', $id)->exists();
        if (! $ticketExists) {
            abort(404);
        }

        $isInternal = ($data['visibility'] ?? 'customer') === 'internal';

        $commentId = $this->ecommerce()->table('rma_comments')->insertGetId([
            'rma_request_id' => $id,
            'user_id' => null,
            'comment' => $data['comment'],
            'is_internal' => $isInternal,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->safeAudit([
            'actor_user_id' => $request->user()?->id,
            'entity_type' => 'rma',
            'entity_id' => (string) $id,
            'action' => 'comment_added',
            'payload' => [
                'comment_id' => $commentId,
                'visibility' => $data['visibility'] ?? 'customer',
            ],
        ]);

        return response()->json(['status' => 'ok', 'comment_id' => $commentId], 201);
    }
}
