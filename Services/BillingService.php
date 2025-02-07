<?php

namespace App\Services;

use App\Http\Resources\BillingResource;
use App\Models\BillingCredit;
use App\Models\BillingDebit;
use App\Models\BillingPayment;
use App\Models\BillingTransaction;
use App\Models\Organization;
use App\Services\SubscriptionService;
use DB;

class BillingService
{
    /**
     * Get all billing history based on the provided request filters.
     *
     * @param Request $request
     * @return mixed
     */
    public function get(object $request, $organizationUuid = NULL)
    {
        if ($organizationUuid !== null) {
            $organization = Organization::with('subscription.plan')->where('uuid', $organizationUuid)->first();
            $organizationId = optional($organization)->id;
        } else {
            $organizationId = null;
        }

        $rows = (new BillingTransaction)->listAll($request->query('search'), $organizationId);

        return BillingResource::collection($rows);
    }

    /**
     * Store a new billing transaction
     *
     * @param Request $request
     */
    public function store($request){
        return DB::transaction(function () use ($request) {
            $organization = Organization::where('uuid', $request->uuid)->firstOrFail();
    
            $modelClass = match ($request->type) {
                'credit' => BillingCredit::class,
                'debit' => BillingDebit::class,
                'payment' => BillingPayment::class,
            };

            $transactionData = [
                'organization_id' => $organization->id,
                'amount' => $request->amount,
            ];
            
            if (in_array($request->type, ['credit', 'debit'])) {
                $transactionData['description'] = $request->description;
            }
            
            if ($request->type === 'payment') {
                $transactionData['processor'] = $request->method;
            }
    
            $entry = $modelClass::create($transactionData);
    
            $transaction = BillingTransaction::create([
                'organization_id' => $organization->id,
                'entity_type' => $request->type,
                'entity_id' => $entry->id,
                'description' => $request->type === 'payment' ? $request->method . ' Transaction' : $request->description,
                'amount' => $request->type === 'debit' || $request->type === 'invoice' ? -$request->amount : $request->amount,
                'created_by' => auth()->user()->id
            ]);

            //Activate organization's plan if credits cover cost of plan
            $subscriptionService = new SubscriptionService();
            $activate = $subscriptionService::activateSubscriptionIfInactiveAndExpiredWithCredits($organization->id, auth()->user()->id);

            return $transaction;
        });
    }
}