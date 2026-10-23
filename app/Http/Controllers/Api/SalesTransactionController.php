<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\WebDesignQuotationMail;
use App\Models\CustomerNotification;
use App\Models\SalesTransaction;
use App\Models\SalesTransactionProposal;
use App\Models\Setting;
use App\Models\User;
use App\Services\ClientOwnerRotator;
use App\Services\CommerceStaffNotifier;
use App\Services\CustomerPortalProvisioner;
use App\Services\PaynamicsService;
use App\Support\DealConversationFile;
use App\Support\DealMeta;
use App\Support\PendingCheckoutGuard;
use App\Support\StorageUrl;
use App\Support\TransactionLabelResolver;
use App\Support\WebDesignMeta;
use App\Support\WebDesignQuotation;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class SalesTransactionController extends Controller
{
    private function transactionRelations(): array
    {
        return [
            'customer:id,fname,lname,email,mname,owner_id,billing_in_charge,contact_person',
            'customer.owner:id,fname,lname,email',
            'user:id,fname,lname,email',
            'clientOwner:id,fname,lname,email',
            'items',
            'proposals',
            'paynamicsPaymentReferences',
            'provisioningRun:id,sales_transaction_id,status,approved_at',
            'provisioningRun.actions:id,provisioning_run_id,service_name,status,done_at,completed_at',
        ];
    }

    public function index(Request $request)
    {
        $perPage = $request->integer('per_page', 10);

        $query = SalesTransaction::query()
            ->with($this->transactionRelations())
            ->when($request->search, function ($q) use ($request) {
                $term = $request->search;
                $q->where(function ($qq) use ($term) {
                    $qq->where('transaction_no', 'like', "%{$term}%")
                       ->orWhere('customer_name', 'like', "%{$term}%")
                       ->orWhere('customer_email', 'like', "%{$term}%")
                       ->orWhereHas('items', fn($itemQuery) => $itemQuery->where('name', 'like', "%{$term}%"));
                });
            })
            ->when($request->customer_id, fn($q) => $q->where('customer_id', $request->customer_id))
            ->when($request->payment_status, fn($q) => $q->where('payment_status', $request->payment_status))
            ->when($request->order_status, fn($q) => $q->where('order_status', $request->order_status))
            ->when($request->boolean('exclude_draft'), fn ($q) => $q->where('order_status', '!=', 'draft'))
            ->when(
                $request->filled('transacted_at_from'),
                fn($q) => $q->whereDate('transacted_at', '>=', $request->input('transacted_at_from'))
            )
            ->when(
                $request->filled('transacted_at_to'),
                fn($q) => $q->whereDate('transacted_at', '<=', $request->input('transacted_at_to'))
            );

        return response()->json(
            $query->latest('created_at')->latest('id')->paginate($perPage)
        );
    }

    public function store(Request $request)
    {
        $validated = $this->validatedPayload($request);
        $items = $validated['items'] ?? [];
        unset($validated['items']);
        $validated = $this->normalizeCustomer($validated);
        $validated['transaction_no'] =
            ($validated['transaction_no'] ?? null) ?: $this->generateTransactionNo();
        $validated['subtotal'] = $this->calculateSubtotal($validated, $items);
        $validated['grand_total'] = $this->calculateGrandTotal($validated);
        $validated['transacted_at'] = $this->normalizeTransactedAt(
            $validated['transacted_at'] ?? null
        );
        $validated['notes'] = DealMeta::applyManagedDates(
            $validated['notes'] ?? null,
            array_map(fn ($item) => (string) ($item['name'] ?? ''), $items)
        );
        $isDraft = $this->isDraftSave($request, $validated);
        if ($isDraft) {
            $validated['order_status'] = 'draft';
            $validated['payment_status'] = $validated['payment_status'] ?? 'pending';
            $missingPrices = DealMeta::hasMissingCatalogPrices($validated['notes'] ?? null, $items);
            $validated['notes'] = DealMeta::merge([
                'pricingWorkflowStatus' => DealMeta::syncPricingWorkflowStatus(
                    $validated['notes'] ?? null,
                    true,
                    $missingPrices
                ),
                'dealCreatedByUserId' => $request->user()?->id,
            ], $validated['notes'] ?? null);
        }
        $explicitOwnerId = isset($validated['client_owner_id'])
            ? (int) $validated['client_owner_id']
            : null;
        unset($validated['client_owner_id']);
        $validated['user_id'] = $request->user()?->id;

        $transaction = DB::transaction(function () use ($validated, $items, $explicitOwnerId) {
            $transaction = SalesTransaction::create($validated);
            $this->syncItems($transaction, $items);
            app(ClientOwnerRotator::class)->assign(
                $transaction,
                $explicitOwnerId ?: null
            );

            return $transaction;
        });

        if ($transaction->customer_id && ! $isDraft) {
            app(CustomerPortalProvisioner::class)
                ->provisionFromTransaction($transaction->fresh(['items']));
        }

        $this->notifyCustomerCareIfWebDesignQuotation($transaction->fresh(['items', 'clientOwner', 'user']));

        return response()->json([
            'message' => 'Sales transaction created successfully',
            'data' => $transaction->load($this->transactionRelations()),
        ], 201);
    }

    /**
     * Creates a pending order and returns a Paynamics-hosted payment URL.
     *
     * No card, bank, e-wallet, or payment instrument data is collected or
     * stored by this application.
     */
    public function checkoutWithPaynamics(
        Request $request,
        PaynamicsService $paynamics
    ) {
        /** @var User|null $customer */
        $customer = $request->user();
        abort_unless($customer, 401);
        abort_unless(
            $customer->hasRole('customer'),
            403,
            'Only client accounts can add items to the cart and complete checkout.'
        );

        /*
         * Validate the profile before creating an order so missing mandatory
         * Paynamics billing fields do not leave an unusable pending order.
         */
        $paynamics->assertCustomerProfile($customer);

        $request->validate([
            'items' => ['required', 'array', 'min:1'],
        ]);

        $validated = $this->validatedPayload($request);
        $items = $this->pricedCheckoutItems($validated['items'] ?? []);
        unset($validated['items']);

        abort_if(
            $items === [],
            422,
            'No priced cart items to pay. Pending quotations are billed on a separate invoice.'
        );

        $validated['customer_id'] = $customer->id;
        $validated['customer_name'] = User::sanitizePersonName(
            trim((string) ($customer->mname ?: '')) ?: $customer->full_name
        );
        $validated['customer_email'] = $customer->email;
        $validated['transaction_no'] =
            ($validated['transaction_no'] ?? null) ?: $this->generateTransactionNo();
        $validated['subtotal'] = $this->calculateSubtotal($validated, $items);
        $validated['grand_total'] = $this->calculateGrandTotal($validated);
        $validated['transacted_at'] = $this->normalizeTransactedAt(
            $validated['transacted_at'] ?? null
        );
        $validated['payment_status'] = 'pending';
        $validated['order_status'] = 'pending';
        $validated['user_id'] = $customer->id;
        unset($validated['client_owner_id']);

        $guard = app(PendingCheckoutGuard::class);
        $reusable = $guard->reusablePending($customer, $items, (float) $validated['grand_total']);
        if ($reusable) {
            $guard->collapseExactDuplicates($customer, $reusable);
            $validated['notes'] = $this->combinedCheckoutNotes(
                $validated['notes'] ?? null,
                $reusable->transaction_no,
                $items
            );

            try {
                $gateway = $paynamics->initiate(
                    $this->prepareReusableCheckout($reusable, $validated['notes']),
                    $customer,
                    $request->ip(),
                    $request->userAgent()
                );
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                report($exception);

                $payload = [
                    'message' => 'Your pending invoice was kept, but Paynamics could not be opened. Try continuing that payment again.',
                    'data' => [
                        'invoice_id' => PendingCheckoutGuard::invoiceId($reusable),
                        'transaction_no' => $reusable->transaction_no,
                    ],
                ];

                if (config('app.debug')) {
                    $payload['error'] = $exception->getMessage();
                }

                return response()->json($payload, 502);
            }

            return response()->json([
                'message' => 'You already have a pending Paynamics payment for '.PendingCheckoutGuard::invoiceId($reusable).'. Continuing that invoice instead of creating a new one.',
                'reused' => true,
                'data' => $reusable->fresh([
                    'customer:id,fname,lname,email',
                    'items',
                ]),
                'paynamics' => $gateway,
            ]);
        }

        $overlapping = $guard->overlappingPending($customer, $items);
        if ($overlapping) {
            $invoiceId = PendingCheckoutGuard::invoiceId($overlapping);
            $service = TransactionLabelResolver::productCategoryFromTransaction($overlapping->notes, $overlapping->items);

            return response()->json([
                'message' => "You already have a pending Paynamics payment for {$service} ({$invoiceId}). Finish that payment instead of creating a new invoice.",
                'errors' => [
                    'checkout' => [
                        "You already have a pending Paynamics payment for {$service} ({$invoiceId}). Finish that payment instead of creating a new invoice.",
                    ],
                ],
                'data' => [
                    'invoice_id' => $invoiceId,
                    'transaction_no' => $overlapping->transaction_no,
                    'service_name' => $service,
                ],
            ], 409);
        }

        $validated['notes'] = $this->combinedCheckoutNotes(
            $validated['notes'] ?? null,
            $validated['transaction_no'],
            $items
        );

        try {
            [$transaction, $gateway] = DB::transaction(function () use (
                $validated,
                $items,
                $paynamics,
                $customer,
                $request
            ) {
                $transaction = SalesTransaction::create($validated);
                $this->syncItems($transaction, $items);
                app(ClientOwnerRotator::class)->assign($transaction);

                $transaction = $transaction->fresh(['items']);

                $gateway = $paynamics->initiate(
                    $transaction,
                    $customer,
                    $request->ip(),
                    $request->userAgent()
                );

                return [$transaction, $gateway];
            });
        } catch (QueryException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Checkout failed because the transaction could not be saved. No transaction was created.',
            ], 500);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            $payload = [
                'message' => 'Checkout could not be completed. No transaction was created.',
            ];

            if (config('app.debug')) {
                $payload['error'] = $exception->getMessage();
            }

            return response()->json($payload, 502);
        }

        return response()->json([
            'message' => 'Redirecting to the Paynamics payment portal.',
            'data' => $transaction->load([
                'customer:id,fname,lname,email',
                'items',
            ]),
            'paynamics' => $gateway,
        ], 201);
    }

    public function continuePaynamicsCheckout(Request $request, PaynamicsService $paynamics)
    {
        /** @var User|null $customer */
        $customer = $request->user();
        abort_unless($customer, 401);
        abort_unless(
            $customer->hasRole('customer'),
            403,
            'Only client accounts can continue Paynamics checkout.'
        );

        $validated = $request->validate([
            'invoice_id' => ['required', 'string', 'max:120'],
        ]);

        $transaction = $this->findPendingCheckoutInvoice($customer, $validated['invoice_id']);
        abort_unless(
            $transaction,
            422,
            'No pending Paynamics payment was found for this invoice.'
        );

        $paynamics->assertCustomerProfile($customer, $transaction);

        try {
            $gateway = $paynamics->initiate(
                $this->prepareReusableCheckout($transaction, (string) $transaction->notes),
                $customer,
                $request->ip(),
                $request->userAgent()
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            $payload = [
                'message' => trim($exception->getMessage()) !== ''
                    ? $exception->getMessage()
                    : 'Paynamics could not be opened for this pending invoice. Try again.',
                'data' => [
                    'invoice_id' => PendingCheckoutGuard::invoiceId($transaction),
                    'transaction_no' => $transaction->transaction_no,
                ],
            ];

            if (config('app.debug')) {
                $payload['error'] = $exception->getMessage();
            }

            return response()->json($payload, 502);
        }

        return response()->json([
            'message' => 'Continuing pending Paynamics payment for '.PendingCheckoutGuard::invoiceId($transaction).'.',
            'reused' => true,
            'data' => $transaction->fresh([
                'customer:id,fname,lname,email',
                'items',
            ]),
            'paynamics' => $gateway,
        ]);
    }

    public function show(SalesTransaction $salesTransaction)
    {
        return response()->json([
            'data' => $salesTransaction->load($this->transactionRelations()),
        ]);
    }

    public function proposals(SalesTransaction $salesTransaction)
    {
        $rows = $salesTransaction->proposals()->latest()->get()->map(
            fn (SalesTransactionProposal $proposal) => $this->mapProposal($proposal)
        );

        return response()->json(['data' => $rows]);
    }

    public function uploadProposal(Request $request, SalesTransaction $salesTransaction)
    {
        $this->assertSalesCanManageWebDesign($request, $salesTransaction);
        abort_unless(WebDesignQuotation::isWebDesign($salesTransaction), 422, 'This order is not a web design quotation.');
        abort_unless(WebDesignQuotation::isPendingQuotation($salesTransaction), 422, 'Payment has already been requested for this quotation.');

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,png,jpg,jpeg', 'max:10240'],
        ]);

        $file = $validated['file'];
        $path = $file->store('web-design-proposals/' . $salesTransaction->id, 'public');

        $proposal = SalesTransactionProposal::create([
            'sales_transaction_id' => $salesTransaction->id,
            'uploaded_by' => $request->user()?->id,
            'version' => 1,
            'kind' => 'proposal',
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
        ]);

        $salesTransaction->update([
            'notes' => DealMeta::appendSalesNote(
                WebDesignQuotation::appendMarker(
                    $salesTransaction->notes,
                    WebDesignQuotation::PROPOSAL_SUBMITTED
                ),
                '',
                [
                    'name' => $file->getClientOriginalName(),
                    'url' => StorageUrl::publicAsset($path) ?: '',
                    'kind' => 'proposal',
                ]
            ),
        ]);

        if ($salesTransaction->customer_id) {
            CustomerNotification::query()->updateOrCreate(
                [
                    'customer_id' => $salesTransaction->customer_id,
                    'reference_key' => 'webdesign-proposal:'.$salesTransaction->id,
                ],
                [
                    'title' => 'Proposal Quotation Ready',
                    'body' => 'A proposal quotation for '
                        . $salesTransaction->transaction_no
                        . ' is ready to review. Download it, sign it, and re-upload the signed copy.',
                    'type' => 'billing',
                    'action_url' => '/public/dashboard?tab=billing',
                    'read_at' => null,
                ]
            );
        }

        return response()->json([
            'message' => 'Proposal quotation uploaded. The client can now download and sign it.',
            'data' => $this->mapProposal($proposal),
        ], 201);
    }

    public function proceedPayment(Request $request, SalesTransaction $salesTransaction)
    {
        $this->assertSalesCanManageWebDesign($request, $salesTransaction);
        abort_unless(WebDesignQuotation::isWebDesign($salesTransaction), 422, 'This order is not a web design quotation.');
        abort_unless(
            WebDesignQuotation::hasMarker($salesTransaction, WebDesignQuotation::PROPOSAL_SIGNED),
            422,
            'The client must sign and re-upload the proposal before payment can proceed.'
        );
        abort_if(
            (float) $salesTransaction->grand_total <= 0,
            422,
            'Set the web design price before requesting payment.'
        );

        $salesTransaction->update([
            'notes' => WebDesignQuotation::appendMarker(
                $salesTransaction->notes,
                WebDesignQuotation::PAYMENT_REQUESTED
            ),
            'payment_status' => 'pending',
            'order_status' => 'pending',
        ]);

        if ($salesTransaction->customer_id) {
            CustomerNotification::create([
                'customer_id' => $salesTransaction->customer_id,
                'title' => 'Upload Proof of Payment',
                'body' => 'Please upload your proof of payment for invoice INV-'
                    . $salesTransaction->transaction_no
                    . '. Attach a receipt file from Billing.',
                'type' => 'billing',
                'action_url' => '/public/dashboard?tab=billing',
            ]);
        }

        return response()->json([
            'message' => 'Payment requested. The client has been notified to upload proof of payment.',
            'data' => $salesTransaction->fresh(['items', 'proposals', 'user', 'customer', 'clientOwner']),
        ]);
    }

    private function mapProposal(SalesTransactionProposal $proposal): array
    {
        return [
            'id' => $proposal->id,
            'version' => (int) $proposal->version,
            'kind' => $proposal->kind,
            'fileName' => $proposal->file_name,
            'fileUrl' => StorageUrl::publicAsset($proposal->file_path),
            'uploadedAt' => optional($proposal->created_at)->format('Y-m-d H:i'),
        ];
    }

    public function update(
        Request $request,
        SalesTransaction $salesTransaction
    ) {
        $request->merge([
            'transaction_no' => $request->input(
                'transaction_no',
                $salesTransaction->transaction_no
            ),
            'customer_id' => $request->input(
                'customer_id',
                $salesTransaction->customer_id
            ),
            'customer_name' => $request->input(
                'customer_name',
                $salesTransaction->customer_name
            ),
            'customer_email' => $request->input(
                'customer_email',
                $salesTransaction->customer_email
            ),
            'subtotal' => $request->input(
                'subtotal',
                $salesTransaction->subtotal
            ),
            'discount_total' => $request->input(
                'discount_total',
                $salesTransaction->discount_total
            ),
            'tax_total' => $request->input(
                'tax_total',
                $salesTransaction->tax_total
            ),
            'shipping_total' => $request->input(
                'shipping_total',
                $salesTransaction->shipping_total
            ),
            'payment_status' => $request->input(
                'payment_status',
                $salesTransaction->payment_status
            ),
            'order_status' => $request->input(
                'order_status',
                $salesTransaction->order_status
            ),
            'notes' => $request->has('notes')
                ? $request->input('notes')
                : $salesTransaction->notes,
        ]);

        if ($request->exists('transacted_at')) {
            $request->merge([
                'transacted_at' => $request->input('transacted_at'),
            ]);
        }

        $previousGrandTotal = round((float) $salesTransaction->grand_total, 2);
        $previousSalesNotes = DealMeta::salesNotes($salesTransaction->notes)
            ?: WebDesignMeta::salesNotes($salesTransaction->notes);

        $validated = $this->validatedPayload($request, $salesTransaction->id);
        $items = $validated['items'] ?? null;
        unset($validated['items']);
        $validated = $this->normalizeCustomer($validated);
        $validated['transaction_no'] =
            ($validated['transaction_no'] ?? null) ?: $salesTransaction->transaction_no;
        $validated['subtotal'] = $this->calculateSubtotal($validated, $items);
        $validated['grand_total'] = $this->calculateGrandTotal($validated);
        if (array_key_exists('transacted_at', $validated)) {
            $validated['transacted_at'] = $this->normalizeTransactedAt(
                $validated['transacted_at']
            );
        }
        $itemNames = is_array($items)
            ? array_map(fn ($item) => (string) ($item['name'] ?? ''), $items)
            : $salesTransaction->items()->pluck('name')->all();
        $validated['notes'] = DealMeta::applyManagedDates($validated['notes'] ?? null, $itemNames);
        $isDraft = $this->isDraftSave($request, $validated, $salesTransaction);
        $wasDraft = DealMeta::isDraftOrder($salesTransaction);
        if ($isDraft) {
            $validated['order_status'] = 'draft';
            $itemSource = is_array($items) ? $items : $salesTransaction->items->all();
            $missingPrices = DealMeta::hasMissingCatalogPrices($validated['notes'] ?? null, $itemSource);
            $validated['notes'] = DealMeta::merge([
                'pricingWorkflowStatus' => DealMeta::syncPricingWorkflowStatus(
                    $validated['notes'] ?? null,
                    true,
                    $missingPrices
                ),
            ], $validated['notes'] ?? null);
        } elseif ($wasDraft && ! $this->isDraftSave($request, $validated, $salesTransaction)) {
            if (strtolower((string) ($validated['order_status'] ?? '')) === 'draft') {
                $validated['order_status'] = 'pending';
            }
            $validated['notes'] = DealMeta::merge([
                'pricingWorkflowStatus' => '',
            ], $validated['notes'] ?? null);
        }
        $conversationClient = DealMeta::clientNotes($validated['notes'])
            ?: WebDesignMeta::clientNotes($validated['notes']);
        $conversationSales = DealMeta::salesNotes($validated['notes'])
            ?: WebDesignMeta::salesNotes($validated['notes']);
        if ($conversationClient !== '' || $conversationSales !== '') {
            $validated['notes'] = DealMeta::withConversation(
                $validated['notes'],
                $conversationClient !== '' ? $conversationClient : null,
                $conversationSales !== '' ? $conversationSales : null
            );
            if (WebDesignQuotation::isWebDesign($salesTransaction) || WebDesignMeta::parse($validated['notes']) !== []) {
                $validated['notes'] = WebDesignMeta::withConversation(
                    $validated['notes'],
                    $conversationClient !== '' ? $conversationClient : null,
                    $conversationSales !== '' ? $conversationSales : null
                );
            }
        }

        $nextQuoted = round((float) ($validated['grand_total'] ?? 0), 2);
        $linePricesChanged = false;
        $salesTransaction->loadMissing('items');
        if (is_array($items)) {
            $previousLines = $salesTransaction->items->mapWithKeys(function ($item) {
                return [strtolower(trim((string) $item->name)) => round((float) $item->total_price, 2)];
            });
            foreach ($items as $item) {
                $key = strtolower(trim((string) ($item['name'] ?? '')));
                $nextLine = round((float) ($item['total_price'] ?? $item['price'] ?? 0), 2);
                $previousLine = round((float) ($previousLines[$key] ?? -1), 2);
                if (abs($previousLine - $nextLine) >= 0.01) {
                    $linePricesChanged = true;
                    break;
                }
            }
        }
        $quoteSent = false;
        if (
            WebDesignQuotation::isWebDesign($salesTransaction)
            && $nextQuoted > 0
            && (abs($nextQuoted - $previousGrandTotal) >= 0.01 || $linePricesChanged)
        ) {
            $notesBeforeQuote = (string) ($validated['notes'] ?? '');
            $validated['notes'] = DealMeta::pinQuotedPrice($notesBeforeQuote, $nextQuoted, [
                'orderNo' => (string) $salesTransaction->transaction_no,
                'items' => is_array($items) ? $items : $salesTransaction->items,
                'subtotal' => (float) ($validated['subtotal'] ?? 0),
                'discount' => (float) ($validated['discount_total'] ?? 0),
                'tax' => (float) ($validated['tax_total'] ?? 0),
                'refreshLines' => $linePricesChanged,
            ]);
            $quoteSent = (string) $validated['notes'] !== $notesBeforeQuote;
        }

        $explicitOwnerId = array_key_exists('client_owner_id', $validated)
            ? ($validated['client_owner_id'] ? (int) $validated['client_owner_id'] : null)
            : null;

        DB::transaction(function () use ($salesTransaction, $validated, $items, $explicitOwnerId) {
            $salesTransaction->update($validated);
            if (is_array($items)) {
                $this->syncItems($salesTransaction, $items);
            }
            if ($explicitOwnerId && $salesTransaction->customer_id) {
                $salesTransaction->loadMissing('items');
                if (WebDesignQuotation::isWebDesign($salesTransaction)) {
                    $assignee = User::query()->with('roles')->find($explicitOwnerId);
                    if (app(ClientOwnerRotator::class)->isAllowedSalesAssignee($assignee)) {
                        $salesTransaction->update([
                            'client_owner_id' => $explicitOwnerId,
                            'user_id' => $explicitOwnerId,
                        ]);
                    }
                } else {
                    $salesTransaction->update(['client_owner_id' => $explicitOwnerId]);
                    User::query()->whereKey($salesTransaction->customer_id)->update(['owner_id' => $explicitOwnerId]);
                }
            }
        });

        if ($salesTransaction->customer_id && ! DealMeta::isDraftOrder($salesTransaction->fresh())) {
            app(CustomerPortalProvisioner::class)
                ->refreshServicesFromTransaction(
                    $salesTransaction->fresh(['items'])
                );
        }

        $fresh = $salesTransaction->fresh(['items']);
        $draftPriceNotified = false;
        if ($fresh && ($isDraft || $wasDraft)) {
            $draftPriceNotified = $this->notifyDraftPriceUpdated(
                $fresh,
                $previousGrandTotal,
                $linePricesChanged,
                $request->user(),
                $wasDraft,
            );
        }
        if ($fresh && $quoteSent) {
            $this->notifyCustomerQuotationPriced($fresh, $previousGrandTotal > 0);
            $actor = $request->user();
            $actorName = trim((string) (($actor->fname ?? '').' '.($actor->lname ?? '')));
            $this->notifyAdminQuotationPriced($fresh, $actorName !== '' ? $actorName : 'Sales', $previousGrandTotal > 0);
        }
        if ($fresh && $fresh->customer_id && ! $quoteSent) {
            $this->notifyDealConversation($fresh, $previousSalesNotes, $request->user());
        }

        return response()->json([
            'message' => 'Sales transaction updated successfully',
            'quoted_price_sent' => $quoteSent,
            'draft_price_notified' => $draftPriceNotified,
            'data' => $salesTransaction->fresh()->load($this->transactionRelations()),
        ]);
    }

    public function replyConversation(Request $request, SalesTransaction $salesTransaction)
    {
        $user = $request->user();
        abort_unless($user, 401);
        abort_if($user->hasRole('customer'), 403, 'Staff only.');
        if (WebDesignQuotation::isWebDesign($salesTransaction)) {
            $this->assertSalesCanManageWebDesign($request, $salesTransaction);
        }

        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:2000'],
            'file' => ['nullable', 'file', 'mimes:pdf,doc,docx,png,jpg,jpeg', 'max:10240'],
        ]);

        $message = trim((string) ($validated['message'] ?? ''));
        abort_if($message === '' && ! $request->hasFile('file'), 422, 'Enter a message or attach a file.');

        $fileMeta = null;
        if ($request->hasFile('file')) {
            $fileMeta = DealConversationFile::store(
                $salesTransaction,
                $request->file('file'),
                'sales',
                $user->id
            );
        }

        $previousSalesNotes = DealMeta::salesNotes($salesTransaction->notes)
            ?: WebDesignMeta::salesNotes($salesTransaction->notes);

        $salesTransaction->update([
            'notes' => DealMeta::appendSalesNote($salesTransaction->notes, $message, $fileMeta),
        ]);

        $fresh = $salesTransaction->fresh(['items']);
        if ($fresh && $fresh->customer_id) {
            $this->notifyDealConversation($fresh, $previousSalesNotes, $user);
            if (($fileMeta['kind'] ?? '') === 'proposal') {
                CustomerNotification::query()->updateOrCreate(
                    [
                        'customer_id' => $fresh->customer_id,
                        'reference_key' => 'webdesign-proposal:'.$salesTransaction->id,
                    ],
                    [
                        'title' => 'Proposal Quotation Ready',
                        'body' => 'A proposal quotation for '
                            .$fresh->transaction_no
                            .' is ready to review. Download it, sign it, and re-upload the signed copy.',
                        'type' => 'billing',
                        'action_url' => '/public/dashboard?tab=billing',
                        'read_at' => null,
                    ]
                );
            }
        }

        $notes = $fresh?->notes;

        return response()->json([
            'message' => 'Reply sent.',
            'data' => [
                'recordId' => $salesTransaction->id,
                'notes' => $notes,
                'clientNotes' => DealMeta::clientNotes($notes) ?: WebDesignMeta::clientNotes($notes),
                'salesNotes' => DealMeta::salesNotes($notes) ?: WebDesignMeta::salesNotes($notes),
                'conversation' => DealMeta::conversation($notes),
            ],
        ]);
    }

    public function downloadConversationFile(Request $request, SalesTransaction $salesTransaction)
    {
        $user = $request->user();
        abort_unless($user, 401);
        abort_if($user->hasRole('customer'), 403, 'Staff only.');

        return DealConversationFile::download(
            $salesTransaction,
            (string) $request->query('path', $request->query('name', '')),
            $request->boolean('download')
        );
    }

    public function conversation(Request $request, SalesTransaction $salesTransaction)
    {
        $user = $request->user();
        abort_unless($user, 401);
        abort_if($user->hasRole('customer'), 403, 'Staff only.');

        $notes = $salesTransaction->notes;

        return response()->json([
            'data' => [
                'recordId' => $salesTransaction->id,
                'notes' => $notes,
                'clientNotes' => DealMeta::clientNotes($notes) ?: WebDesignMeta::clientNotes($notes),
                'salesNotes' => DealMeta::salesNotes($notes) ?: WebDesignMeta::salesNotes($notes),
                'conversation' => DealMeta::conversation($notes),
                'quotedPrice' => DealMeta::quotedPrice($notes),
            ],
        ]);
    }

    public function requestDealPrice(Request $request, SalesTransaction $salesTransaction)
    {
        $actor = $request->user();
        abort_unless($actor, 401);
        abort_if($actor->hasRole('customer'), 403, 'Staff only.');

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'assignee_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $message = trim($validated['message']);
        $assigneeId = (int) $validated['assignee_id'];
        abort_if($message === '', 422, 'Enter a note for the pricing assignee.');

        $salesTransaction->loadMissing(['items', 'customer']);
        $previousSalesNotes = DealMeta::salesNotes($salesTransaction->notes);
        $stamp = now()->format('Y-m-d H:i').' — Price request: '.$message;
        $salesNotes = trim($previousSalesNotes === '' ? $stamp : $previousSalesNotes."\n\n".$stamp);
        $notes = DealMeta::withConversation($salesTransaction->notes, null, $salesNotes);
        $notes = DealMeta::merge([
            'pricingWorkflowStatus' => DealMeta::PRICING_AWAITING,
            'priceAssigneeId' => $assigneeId,
            'priceRequestedAt' => now()->toIso8601String(),
            'dealCreatedByUserId' => (int) (DealMeta::parse($notes)['dealCreatedByUserId'] ?? $salesTransaction->user_id ?? $actor->id),
        ], $notes);

        $salesTransaction->update([
            'order_status' => 'draft',
            'notes' => $notes,
        ]);

        $actorName = trim((string) (($actor->fname ?? '').' '.($actor->lname ?? ''))) ?: 'Sales';
        $dealLabel = TransactionLabelResolver::planLabel($salesTransaction->items, $salesTransaction->transaction_no);
        $clientLabel = trim((string) ($salesTransaction->customer_name ?? 'Client'));

        app(CommerceStaffNotifier::class)->notifyStaffIds(
            [$assigneeId],
            'deal-price-request:'.$salesTransaction->id,
            'Deal needs pricing',
            $actorName.' requested pricing for '.$clientLabel
                .($dealLabel ? " ({$dealLabel})" : '')
                .'. '.$message,
            'deal_price_request',
            '/public/commerce-admin?tab=orders&dealsScope=drafts&dealId='.$salesTransaction->id,
        );

        return response()->json([
            'message' => 'Price request sent.',
            'data' => $salesTransaction->fresh()->load($this->transactionRelations()),
        ]);
    }

    public function destroy(SalesTransaction $salesTransaction)
    {
        $salesTransaction->delete();

        return response()->json([
            'message' => 'Sales transaction deleted successfully',
        ]);
    }

    private function assertSalesCanManageWebDesign(Request $request, SalesTransaction $transaction): void
    {
        $user = $request->user();
        abort_unless($user, 401);

        if ($user->hasAnyRole(['sales_admin', 'admin'])) {
            return;
        }

        $assignedId = (int) ($transaction->client_owner_id ?: $transaction->user_id);
        if ($assignedId > 0) {
            abort_unless(
                $assignedId === (int) $user->id,
                403,
                'Only the assigned sales owner (Myrna or Michelle) can upload the proposal or proceed to payment for this order.'
            );

            return;
        }

        abort_unless(
            app(ClientOwnerRotator::class)->isAllowedSalesAssignee($user),
            403,
            'Only Myrna Glorioso or Michelle Durian can upload the proposal or proceed to payment for this order.'
        );
    }

    private function validatedPayload(
        Request $request,
        ?int $ignoreId = null
    ): array {
        return $request->validate([
            'transaction_no' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('sales_transactions', 'transaction_no')
                    ->ignore($ignoreId),
            ],
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'client_owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'discount_total' => ['nullable', 'numeric', 'min:0'],
            'tax_total' => ['nullable', 'numeric', 'min:0'],
            'shipping_total' => ['nullable', 'numeric', 'min:0'],
            'payment_status' => ['required', 'string', 'max:50'],
            'order_status' => ['required', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'transacted_at' => ['nullable', 'date'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => [
                'nullable',
                'integer',
                'exists:products,id',
            ],
            'items.*.name' => [
                'required_with:items',
                'string',
                'max:255',
            ],
            'items.*.item_type' => [
                'nullable',
                'string',
                'max:50',
            ],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.total_price' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    private function normalizeCustomer(array $payload): array
    {
        if (!empty($payload['customer_id'])) {
            $customer = User::find($payload['customer_id']);
            if ($customer) {
                $payload['customer_name'] = User::sanitizePersonName(
                    ($payload['customer_name'] ?? null)
                    ?: (trim((string) $customer->mname) ?: $customer->full_name)
                );
                $payload['customer_email'] =
                    ($payload['customer_email'] ?? null) ?: $customer->email;
            }
        }

        return $payload;
    }

    private function calculateGrandTotal(array $payload): float
    {
        return max(
            0,
            (float) ($payload['subtotal'] ?? 0)
                - (float) ($payload['discount_total'] ?? 0)
                + (float) ($payload['tax_total'] ?? 0)
                + (float) ($payload['shipping_total'] ?? 0)
        );
    }

    private function calculateSubtotal(
        array $payload,
        ?array $items
    ): float {
        if (!is_array($items) || count($items) === 0) {
            return (float) ($payload['subtotal'] ?? 0);
        }

        return collect($items)->sum(function ($item) {
            return $this->calculateItemTotal($item);
        });
    }

    private function syncItems(
        SalesTransaction $transaction,
        array $items
    ): void {
        $transaction->items()->delete();

        foreach ($items as $item) {
            $quantity = (float) ($item['quantity'] ?? 1);
            $price = (float) ($item['price'] ?? 0);

            $transaction->items()->create([
                'product_id' => $item['product_id'] ?? null,
                'name' => $item['name'],
                'item_type' => $item['item_type'] ?? 'product',
                'price' => $price,
                'quantity' => $quantity,
                'total_price' => $this->calculateItemTotal($item),
            ]);
        }
    }

    private function calculateItemTotal(array $item): float
    {
        if (
            array_key_exists('total_price', $item) &&
            $item['total_price'] !== null
        ) {
            return (float) $item['total_price'];
        }

        return (float) ($item['price'] ?? 0)
            * (float) ($item['quantity'] ?? 1);
    }

    private function normalizeTransactedAt(mixed $value): Carbon
    {
        if ($value === null || $value === '') {
            return now();
        }

        // Browser sends UTC ISO (toISOString); store wall-clock in app timezone (Asia/Manila).
        return Carbon::parse($value)->timezone(config('app.timezone', 'Asia/Manila'));
    }

    private function pricedCheckoutItems(array $items): array
    {
        return array_values(array_filter($items, function ($item) {
            if (! is_array($item)) {
                return false;
            }

            return $this->checkoutItemHasAvailableAmount($item)
                && ! $this->isPendingQuotationCheckoutItem($item);
        }));
    }

    private function checkoutItemHasAvailableAmount(array $item): bool
    {
        $quantity = (float) ($item['quantity'] ?? 1);
        $price = (float) ($item['price'] ?? 0);
        $total = array_key_exists('total_price', $item) && $item['total_price'] !== null
            ? (float) $item['total_price']
            : $price * $quantity;

        return $total > 0;
    }

    private function isPendingQuotationCheckoutItem(array $item): bool
    {
        $type = strtolower((string) ($item['item_type'] ?? ''));
        $name = strtolower((string) ($item['name'] ?? ''));
        $isWebDesign = str_contains($type, 'web_design')
            || str_contains($type, 'webdesign')
            || str_contains($name, 'web design')
            || str_contains($name, 'starter launch')
            || str_contains($name, 'professional corporate')
            || str_contains($name, 'e-commerce')
            || str_contains($name, 'website template');

        return $isWebDesign && ! $this->checkoutItemHasAvailableAmount($item);
    }

    private function combinedCheckoutNotes(
        ?string $notes,
        string $transactionNo,
        array $items
    ): string {
        $count = count($items);
        $header = $count > 1
            ? "Combined invoice {$transactionNo}: {$count} priced cart items paid in one transaction."
            : "Invoice {$transactionNo}: 1 priced cart item.";
        $current = trim((string) $notes);

        if ($current === '') {
            return $header;
        }

        if (str_contains($current, 'Combined invoice') || str_contains($current, "Invoice {$transactionNo}")) {
            return $current;
        }

        return $header."\n".$current;
    }

    private function findPendingCheckoutInvoice(User $customer, string $invoiceId): ?SalesTransaction
    {
        $wanted = strtolower(trim($invoiceId));
        $wantedNo = preg_replace('/^inv-/', '', $wanted) ?? $wanted;

        return app(PendingCheckoutGuard::class)->unpaidCheckouts($customer)->first(function (SalesTransaction $row) use ($wanted, $wantedNo) {
            $invoice = strtolower(PendingCheckoutGuard::invoiceId($row));
            $transactionNo = strtolower(trim((string) $row->transaction_no));

            return $invoice === $wanted
                || $transactionNo === $wanted
                || $transactionNo === $wantedNo
                || $invoice === 'inv-'.$wantedNo;
        });
    }

    private function prepareReusableCheckout(SalesTransaction $transaction, string $notes): SalesTransaction
    {
        $transaction->update([
            'payment_status' => 'pending',
            'order_status' => 'pending',
            'notes' => $notes,
        ]);

        return $transaction->fresh(['items']);
    }

    private function generateTransactionNo(): string
    {
        $prefix = 'ST-' . now()->format('Ymd') . '-';
        $latest = SalesTransaction::withTrashed()
            ->where('transaction_no', 'like', $prefix . '%')
            ->orderByDesc('transaction_no')
            ->value('transaction_no');

        $latestNumber = 0;
        if ($latest && preg_match('/-(\d+)$/', $latest, $matches)) {
            $latestNumber = (int) $matches[1];
        }

        $next = $latestNumber + 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function notifyCustomerCareIfWebDesignQuotation(SalesTransaction $transaction): void
    {
        $transaction->loadMissing(['items', 'clientOwner.roles', 'user.roles']);

        if (
            ! WebDesignQuotation::isWebDesign($transaction)
            || ! WebDesignQuotation::isPendingQuotation($transaction)
        ) {
            return;
        }

        $items = $transaction->items ?? collect();
        $itemNames = WebDesignMeta::packageItemLine($items, $transaction->notes);
        $clientLabel = $transaction->customer_name
            ?: ($transaction->customer?->full_name ?? 'Client');
        $detailSuffix = WebDesignMeta::inboxDetailSuffix($transaction->notes);

        if ($transaction->customer_id) {
            CustomerNotification::create([
                'customer_id' => $transaction->customer_id,
                'reference_key' => 'webdesign-quotation:'.$transaction->id,
                'title' => 'Web Design Quotation Submitted',
                'body' => 'Your web design quotation request '
                    . $transaction->transaction_no
                    . ($itemNames ? " ({$itemNames})" : '')
                    . ' was sent to Sales. Status is Pending Quotation until the proposal is ready.'
                    . $detailSuffix,
                'type' => 'general',
                'action_url' => '/public/dashboard?tab=billing',
            ]);
        }

        $assignee = $transaction->clientOwner ?: $transaction->user;
        $assigneeIsSales = app(ClientOwnerRotator::class)->isAllowedSalesAssignee($assignee);
        $assigneeName = $assigneeIsSales
            ? (trim(($assignee->fname ?? '') . ' ' . ($assignee->lname ?? '')) ?: ($assignee->email ?? 'Sales Staff'))
            : null;

        $staffBody = "{$clientLabel} submitted a Pending Quotation checkout"
            . ($itemNames ? " for {$itemNames}" : '')
            . " ({$transaction->transaction_no}). "
            . ($assigneeName
                ? "Auto-assigned to {$assigneeName}. They should upload the proposal quotation."
                : 'Assign Myrna Glorioso or Michelle Durian and upload the proposal quotation.')
            . $detailSuffix;

        app(CommerceStaffNotifier::class)->notifyOwnerAndRoles(
            $transaction->customer_id ? (int) $transaction->customer_id : null,
            ['sales_admin', 'admin', 'customer_care'],
            'admin:webdesign-quotation:' . $transaction->id,
            'Web Design Quotation Request',
            $staffBody,
            'web_design_quotation',
            '/public/commerce-admin?tab=orders',
            false,
        );

        if ($assigneeIsSales) {
            CustomerNotification::query()->updateOrCreate(
                [
                    'customer_id' => $assignee->id,
                    'reference_key' => 'admin:webdesign-assigned:' . $transaction->id,
                ],
                [
                    'title' => 'New Web Design Assignment',
                    'body' => 'You were assigned web design order '
                        . $transaction->transaction_no
                        . ($itemNames ? " ({$itemNames})" : '')
                        . '. Upload the proposal quotation in Orders.'
                        . $detailSuffix,
                    'type' => 'web_design_quotation',
                    'action_url' => '/public/commerce-admin?tab=orders',
                    'read_at' => null,
                ]
            );
        }

        $to = Setting::query()->value('email') ?: 'customercare@webfocus.ph';

        try {
            Mail::to($to)->send(new WebDesignQuotationMail($transaction, $assigneeName));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function notifyCustomerQuotationPriced(SalesTransaction $transaction, bool $updated = false): void
    {
        if (! $transaction->customer_id) {
            return;
        }

        $transaction->loadMissing(['items']);
        $amount = DealMeta::quotedPrice($transaction->notes)
            ?: round((float) $transaction->grand_total, 2)
            ?: WebDesignQuotation::displayAmount($transaction);
        $summary = DealMeta::quoteSummary($amount, $transaction->notes, [
            'orderNo' => (string) $transaction->transaction_no,
            'items' => $transaction->items,
            'subtotal' => (float) $transaction->subtotal,
            'discount' => (float) $transaction->discount_total,
            'tax' => (float) $transaction->tax_total,
        ]);

        $notice = CustomerNotification::query()->updateOrCreate(
            [
                'customer_id' => $transaction->customer_id,
                'reference_key' => 'webdesign-quotation-priced:'.$transaction->id,
            ],
            [
                'title' => $updated ? 'Quoted price updated' : 'Quoted price is ready',
                'body' => $summary,
                'type' => 'order',
                'action_url' => '/public/dashboard?tab=orders',
                'read_at' => null,
            ]
        );
        $notice->forceFill([
            'created_at' => now(),
            'read_at' => null,
        ])->save();
    }

    private function notifyAdminQuotationPriced(SalesTransaction $transaction, string $actorName, bool $updated = false): void
    {
        $transaction->loadMissing(['items']);
        $itemNames = WebDesignMeta::packageItemLine($transaction->items, $transaction->notes);
        $amount = WebDesignQuotation::displayAmount($transaction);
        $lines = WebDesignMeta::amountDetailRows($transaction->items, $transaction->notes);
        $lineText = collect($lines)
            ->map(fn (array $row) => $row['value'] ?? '')
            ->filter()
            ->implode('; ');
        $clientLabel = trim((string) ($transaction->customer_name ?: 'Client'));

        $body = $actorName.($updated ? ' updated the quotation amount for ' : ' set the quotation amount for ')
            .$transaction->transaction_no
            .($clientLabel !== '' ? " ({$clientLabel})" : '')
            .($itemNames ? " · {$itemNames}" : '')
            .'. Grand total is ₱'
            .number_format($amount, 2)
            .($lineText !== '' ? '. '.$lineText : '')
            .'. Saved for reference.';

        app(CommerceStaffNotifier::class)->notifyOwnerAndRoles(
            $transaction->customer_id ? (int) $transaction->customer_id : null,
            ['sales_admin', 'admin', 'customer_care', 'sales_staff'],
            'admin:webdesign-priced:'.$transaction->id,
            $updated ? 'Quotation amount updated' : 'Quotation amount set',
            $body,
            'web_design_quotation',
            '/public/commerce-admin?tab=orders',
            false,
        );
        CustomerNotification::query()
            ->where('reference_key', 'admin:webdesign-priced:'.$transaction->id)
            ->update([
                'created_at' => now(),
                'read_at' => null,
            ]);
    }

    private function readableReply(string $notes): string
    {
        $chunks = preg_split("/\n{2,}/", trim($notes)) ?: [];
        for ($index = count($chunks) - 1; $index >= 0; $index--) {
            $text = trim((string) (preg_replace('/\s+/', ' ', (string) $chunks[$index]) ?? ''));
            if ($text === '') {
                continue;
            }
            if (stripos($text, 'Grand total:') !== false || stripos($text, 'A quoted price is ready') !== false) {
                continue;
            }
            if (strlen($text) > 180) {
                continue;
            }

            return $text;
        }

        return '';
    }

    private function notifyCustomerQuotationReply(SalesTransaction $transaction, string $previousSalesNotes): void
    {
        $this->notifyDealConversation($transaction, $previousSalesNotes);
    }

    private function notifyDealConversation(
        SalesTransaction $transaction,
        string $previousSalesNotes,
        ?User $actor = null,
    ): void {
        if (! $transaction->customer_id) {
            return;
        }

        $transaction->loadMissing(['items']);
        $salesNotes = DealMeta::salesNotes($transaction->notes)
            ?: WebDesignMeta::salesNotes($transaction->notes);
        if ($salesNotes === '' || $salesNotes === $previousSalesNotes) {
            return;
        }

        $itemNames = WebDesignMeta::packageItemLine($transaction->items, $transaction->notes)
            ?: TransactionLabelResolver::planLabel($transaction->items, $transaction->transaction_no);
        $isWebDesign = WebDesignQuotation::isWebDesign($transaction);
        $title = $isWebDesign ? 'Sales replied to your quotation' : 'Sales replied to your deal';
        $reply = $this->readableReply($salesNotes);
        $body = 'Sales replied on '
            .$transaction->transaction_no
            .($itemNames ? " ({$itemNames})" : '')
            .($reply !== '' ? '. '.$reply : '.')
            .($isWebDesign ? ' Review the services and total in this quotation.' : '');

        CustomerNotification::query()->updateOrCreate(
            [
                'customer_id' => $transaction->customer_id,
                'reference_key' => ($isWebDesign ? 'webdesign-quotation-reply:' : 'deal-reply:').$transaction->id,
            ],
            [
                'title' => $title,
                'body' => $body,
                'type' => 'order',
                'action_url' => '/public/dashboard?tab=orders',
                'read_at' => null,
            ]
        );

        $actorName = trim((string) (($actor->fname ?? '').' '.($actor->lname ?? '')));
        $who = $actorName !== '' ? $actorName : 'Sales';
        app(CommerceStaffNotifier::class)->notifyOwnerAndRoles(
            (int) $transaction->customer_id,
            ['sales_admin', 'admin', 'sales_staff'],
            'deal-reply:'.$transaction->id,
            $isWebDesign ? 'Sales replied to a quotation' : 'Sales replied to a deal',
            $who
                .' replied on '
                .$transaction->transaction_no
                .($itemNames ? " ({$itemNames})" : '')
                .($reply !== '' ? '. '.$reply : '.'),
            'deal_reply',
            '/public/commerce-admin?tab=orders',
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function isDraftSave(Request $request, array $validated, ?SalesTransaction $existing = null): bool
    {
        $mode = strtolower(trim((string) $request->input('save_mode', '')));
        if ($mode === 'finalize') {
            return false;
        }
        if ($mode === 'draft') {
            return true;
        }

        return strtolower(trim((string) ($validated['order_status'] ?? $existing?->order_status ?? ''))) === 'draft';
    }

    private function notifyDraftPriceUpdated(
        SalesTransaction $transaction,
        float $previousGrandTotal,
        bool $linePricesChanged,
        ?User $actor,
        bool $wasDraft,
    ): bool {
        $isDraft = DealMeta::isDraftOrder($transaction);
        if (! $isDraft && ! $wasDraft) {
            return false;
        }

        $nextTotal = round((float) $transaction->grand_total, 2);
        $priceChanged = $linePricesChanged || abs($nextTotal - $previousGrandTotal) >= 0.01;
        if (! $priceChanged || $nextTotal <= 0) {
            return false;
        }

        $meta = DealMeta::parse($transaction->notes);
        $actorId = (int) ($actor?->id ?? 0);
        $recipientIds = array_values(array_unique(array_filter([
            (int) ($meta['dealCreatedByUserId'] ?? 0),
            (int) ($meta['priceAssigneeId'] ?? 0),
        ], fn (int $id) => $id > 0 && $id !== $actorId)));

        if ($recipientIds === []) {
            return false;
        }

        $actorName = trim((string) (($actor->fname ?? '').' '.($actor->lname ?? ''))) ?: 'Staff';
        $dealLabel = TransactionLabelResolver::planLabel($transaction->items, $transaction->transaction_no);
        $action = $isDraft
            ? 'set pricing on draft '.$transaction->transaction_no
            : 'updated pricing and finalized '.$transaction->transaction_no;
        $scope = $isDraft ? 'drafts' : 'active';

        app(CommerceStaffNotifier::class)->notifyStaffIds(
            $recipientIds,
            'deal-price-updated:'.$transaction->id.':'.(int) round($nextTotal * 100),
            'Deal price updated',
            $actorName.' '.$action
                .($dealLabel ? " ({$dealLabel})" : '')
                .' — '.$this->formatPeso($nextTotal).'. Review and complete the deal.',
            'deal_price_request',
            '/public/commerce-admin?tab=orders&dealsScope='.$scope.'&dealId='.$transaction->id,
        );

        return true;
    }

    private function formatPeso(float $amount): string
    {
        return '₱'.number_format($amount, 2, '.', ',');

    }
}
