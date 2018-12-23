<?php

namespace Themsaid\CashierTool;

use App\Spark;
use Stripe\Plan;
use Stripe\Refund;
use Stripe\Stripe;
use Stripe\Dispute;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Config\Repository;
use Illuminate\Routing\Controller;
use Stripe\Subscription as StripeSubscription;
use Benmag\SparkAddons\Contracts\Interactions\CancelTeamAddonSubscription;
use Benmag\SparkAddons\Contracts\Interactions\ResumeTeamAddonSubscription;

class CashierToolController extends Controller
{
    /**
     * The model used by Stripe.
     *
     * @var string
     */
    public $stripeModel;

    /**
     * The subscription name.
     *
     * @var string
     */
    public $subscriptionName;

    /**
     * Create a new controller instance.
     *
     * @param \Illuminate\Config\Repository $config
     */
    public function __construct(Repository $config)
    {
        $this->middleware(function ($request, $next) use ($config) {
            Stripe::setApiKey($config->get('services.stripe.secret'));

            $this->stripeModel = $config->get('services.stripe.model');

            $this->subscriptionName = $config->get('nova-cashier-manager.subscription_name');

            return $next($request);
        });
    }

    /**
     * Return the user response.
     *
     * @param  int $billableId
     * @param  bool $brief
     * @return \Illuminate\Http\Response
     */
    public function user($billableId)
    {
        $billable = (new $this->stripeModel)->find($billableId);

        $subscription = $billable->subscription($this->subscriptionName);

        if (! $subscription) {
            return [
                'subscription' => null,
            ];
        }

        $stripeSubscription = StripeSubscription::retrieve($subscription->stripe_id);
        return [
            'user' => $billable->toArray(),
            'cards' => request('brief') ? [] : $this->formatCards($billable->cards(), $billable->defaultCard()->id),
            'invoices' => request('brief') ? [] : $this->formatInvoices($billable->invoicesIncludingPending()),
            'charges' => request('brief') ? [] : $this->formatCharges($billable->asStripeCustomer()->charges()),
            'subscription' => $this->formatSubscription($subscription, $stripeSubscription),
            'addon_subscriptions' => collect(\Spark::addonSubscriptionsToBeSettled($billable))->map(function($addon) {
                $addon->ended = $addon->ended();
                $addon->cancelled = $addon->cancelled();
                $addon->active = $addon->active();
                $addon->on_trial = $addon->onTrial();
                $addon->on_grace_period = $addon->onGracePeriod();
                return $addon;
            }),
            'plans' => request('brief') ? [] : $this->formatPlans(Plan::all(['limit' => 100])),
        ];
    }


    /**
     * Cancel the given subscription.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $billableId
     * @return \Illuminate\Http\Response
     */
    public function cancelSubscription(Request $request, $billableId)
    {
        $billable = (new $this->stripeModel)->find($billableId);

        if ($request->input('now')) {
            $billable->subscription($this->subscriptionName)->cancelNow();
        } else {
            $billable->subscription($this->subscriptionName)->cancel();
        }


        if(Spark::usesTeams()) {
            event(new \Laravel\Spark\Events\Teams\Subscription\SubscriptionCancelled($billable->fresh()));
        } else {
            event(new \Laravel\Spark\Events\Subscription\SubscriptionCancelled($billable->fresh()));
        }
    }
    /**
     * Update the given subscription.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $billableId
     * @return \Illuminate\Http\Response
     */
    public function updateSubscription(Request $request, $billableId)
    {
        $billable = (new $this->stripeModel)->find($billableId);

        $billable->subscription($this->subscriptionName)->swap($request->input('plan'));

        if(Spark::usesTeams()) {
            event(new \Laravel\Spark\Events\Teams\Subscription\SubscriptionUpdated($billable->fresh()));
        } else {
            event(new \Laravel\Spark\Events\Subscription\SubscriptionUpdated($billable->fresh()));
        }
    }
    /**
     * Resume the given subscription.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $billableId
     * @param  int $subscriptionId
     * @return \Illuminate\Http\Response
     */
    public function resumeSubscription(Request $request, $billableId)
    {
        $billable = (new $this->stripeModel)->find($billableId);

        $subscription = $billable->subscription($this->subscriptionName);

        $subscription->swap($subscription->stripe_plan);

        if(Spark::usesTeams()) {
            event(new \Laravel\Spark\Events\Teams\Subscription\SubscriptionUpdated($billable->fresh()));
        } else {
            event(new \Laravel\Spark\Events\Subscription\SubscriptionUpdated($billable->fresh()));
        }
    }

    /**
     * Cancel the given add-on subscription.
     *
     * @param Request $request
     * @param $billableId
     * @param $addonId
     */
    public function cancelAddonSubscription(Request $request, $billableId, $addonId)
    {

        $billable = (new $this->stripeModel)->find($billableId);

        // Get the add-on subscription
        $addonSubscription = $billable->addonSubscriptions()->findOrFail($addonId);

        // Find the add-on plan
        $addonPlan = Spark::findAddonPlanById($addonSubscription->subscription->provider_plan);

        // Cancel addon subscription
        Spark::call(CancelTeamAddonSubscription::class, [$addonSubscription, $addonPlan]);

        // Cancel the add-on
        if(!empty($addonPlan->onCancel)) {
            dispatch(new $addonPlan->onCancel($billable, $addonSubscription));
        }

    }

    public function resumeAddonSubscription(Request $request, $billableId, $addonId)
    {

        $billable = (new $this->stripeModel)->find($billableId);

        // Get the add-on subscription
        $addonSubscription = $billable->addonSubscriptions()->findOrFail($addonId);

        // Find the add-on plan
        $addonPlan = Spark::findAddonPlanById($addonSubscription->subscription->provider_plan);

        // Resume
        Spark::call(ResumeTeamAddonSubscription::class, [$addonSubscription, $addonPlan]);

        // Resume the add-on
        if(!empty($addonPlan->onResume)) {
            dispatch(new $addonPlan->onResume($billable, $addonSubscription));
        }
    }

    /**
     * Refund the given charge.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $billableId
     * @param  string $stripeChargeId
     * @return \Illuminate\Http\Response
     */
    public function refundCharge(Request $request, $billableId, $stripeChargeId)
    {
        $refundParameters = ['charge' => $stripeChargeId];

        if ($request->input('amount')) {
            $refundParameters['amount'] = $request->input('amount');
        }

        if ($request->input('notes')) {
            $refundParameters['metadata'] = ['notes' => $request->input('notes')];
        }

        Refund::create($refundParameters);
    }

    /**
     * Format a a subscription object.
     *
     * @param  \Laravel\Cashier\Subscription $subscription
     * @param  \Stripe\Subscription $stripeSubscription
     * @return array
     */
    public function formatSubscription($subscription, $stripeSubscription)
    {
        $stripeSubscription->plan = collect($stripeSubscription->items->data)->firstWhere('id', $subscription->stripe_item_id)->plan;
        return array_merge($subscription->toArray(), [
            'plan_amount' => $stripeSubscription->plan->amount,
            'plan_interval' => $stripeSubscription->plan->interval,
            'plan_currency' => $stripeSubscription->plan->currency,
            'plan' => $subscription->stripe_plan,
            'stripe_plan' => $stripeSubscription->plan->id,
            'ended' => $subscription->ended(),
            'cancelled' => $subscription->cancelled(),
            'active' => $subscription->active(),
            'on_trial' => $subscription->onTrial(),
            'on_grace_period' => $subscription->onGracePeriod(),
            'charges_automatically' => $stripeSubscription->billing == 'charge_automatically',
            'created_at' => $stripeSubscription->billing_cycle_anchor ? Carbon::createFromTimestamp($stripeSubscription->billing_cycle_anchor)->toDateTimeString() : null,
            'ended_at' => $stripeSubscription->ended_at ? Carbon::createFromTimestamp($stripeSubscription->ended_at)->toDateTimeString() : null,
            'current_period_start' => $stripeSubscription->current_period_start ? Carbon::createFromTimestamp($stripeSubscription->current_period_start)->toDateString() : null,
            'current_period_end' => $stripeSubscription->current_period_end ? Carbon::createFromTimestamp($stripeSubscription->current_period_end)->toDateString() : null,
            'days_until_due' => $stripeSubscription->days_until_due,
            'cancel_at_period_end' => $stripeSubscription->cancel_at_period_end,
            'canceled_at' => $stripeSubscription->canceled_at,
        ]);
    }

    /**
     * Format the cards collection.
     *
     * @param  array $cards
     * @param  null|int $defaultCardId
     * @return array
     */
    private function formatCards($cards, $defaultCardId = null)
    {
        return collect($cards)->map(function ($card) use ($defaultCardId) {
            return [
                'id' => $card->id,
                'is_default' => $card->id == $defaultCardId,
                'name' => $card->name,
                'last4' => $card->last4,
                'country' => $card->country,
                'brand' => $card->brand,
                'exp_month' => $card->exp_month,
                'exp_year' => $card->exp_year,
            ];
        })->toArray();
    }

    /**
     * Format the invoices collection.
     *
     * @param  array $invoices
     * @return array
     */
    private function formatInvoices($invoices)
    {
        return collect($invoices)->map(function ($invoice) {
            return [
                'id' => $invoice->id,
                'total' => $invoice->total,
                'attempted' => $invoice->attempted,
                'charge_id' => $invoice->charge,
                'currency' => $invoice->currency,
                'period_start' => $invoice->period_start ? Carbon::createFromTimestamp($invoice->period_start)->toDateTimeString() : null,
                'period_end' => $invoice->period_end ? Carbon::createFromTimestamp($invoice->period_end)->toDateTimeString() : null,
            ];
        })->toArray();
    }

    /**
     * Format the charges collection.
     *
     * @param  array $charges
     * @return array
     */
    private function formatCharges($charges)
    {
        return collect($charges->data)->map(function ($charge) {
            return [
                'id' => $charge->id,
                'amount' => $charge->amount,
                'amount_refunded' => $charge->amount_refunded,
                'captured' => $charge->captured,
                'paid' => $charge->paid,
                'status' => $charge->status,
                'currency' => $charge->currency,
                'dispute' => $charge->dispute ? Dispute::retrieve($charge->dispute) : null,
                'failure_code' => $charge->failure_code,
                'failure_message' => $charge->failure_message,
                'created' => $charge->created ? Carbon::createFromTimestamp($charge->created)->toDateTimeString() : null,
            ];
        })->toArray();
    }

    /**
     * Format the plans collection.
     *
     * @param  array $charges
     * @return array
     */
    private function formatPlans($plans)
    {
        return collect($plans->data)->map(function ($plan) {
            return [
                'id' => $plan->id,
                'price' => $plan->amount,
                'interval' => $plan->interval,
                'currency' => $plan->currency,
                'interval_count' => $plan->interval_count,
            ];
        })->toArray();
    }
}
