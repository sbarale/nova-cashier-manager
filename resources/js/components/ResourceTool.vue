<script type="text/ecmascript-6">
    export default {
        props: ['resourceName', 'resourceId', 'field'],

        data(){
            return {
                loading: true,
                user: null,
                subscription: null,
                addon_subscriptions: null
            }
        },


        computed: {
            basePath() {
                return Nova.config.base;
            }
        },


        mounted() {
            this.loadUserData();
        },


        methods: {
            loadUserData(){
                axios.get(`/nova-cashier-tool-api/user/${this.resourceId}/?brief=true`)
                        .then(response => {
                            this.user = response.data.user;
                            this.subscription = response.data.subscription;
                            this.addon_subscriptions = response.data.addon_subscriptions;

                            this.loading = false;
                        });
            }
        }
    }
</script>

<template>
    <div>
        <loading-view :loading="loading">
            <p class="text-90" v-if="!subscription">
                User has no subscription.
            </p>

            <div class="flex border-b border-40" v-if="subscription">
                <div class="w-1/4 py-4"><h4 class="font-normal text-80">ID</h4></div>
                <div class="w-3/4 py-4"><p class="text-90">
                    {{subscription.stripe_id}}
                    ·
                    <a class="text-primary no-underline" :href="'https://dashboard.stripe.com/subscriptions/' + subscription.stripe_id" target="_blank">View on Stripe</a>
                </p></div>
            </div>

            <div class="flex border-b border-40" v-if="subscription">
                <div class="w-1/4 py-4"><h4 class="font-normal text-80">Subscribed since</h4></div>
                <div class="w-3/4 py-4"><p class="text-90">{{subscription.created_at}}</p></div>
            </div>

            <div class="flex border-b border-40" v-if="subscription">
                <div class="w-1/4 py-4"><h4 class="font-normal text-80">Billing Period</h4></div>
                <div class="w-3/4 py-4"><p class="text-90">{{subscription.current_period_start}} => {{subscription.current_period_end}}</p></div>
            </div>

            <div class="flex border-b border-40 remove-bottom-border" v-if="subscription">
                <div class="w-1/4 py-4"><h4 class="font-normal text-80">Status</h4></div>
                <div class="w-3/4 py-4">
                    <p class="text-90">
                        <span v-if="subscription.on_grace_period">On Grace Period</span>
                        <span v-if="subscription.cancelled || subscription.cancel_at_period_end" class="text-danger">Cancelled</span>
                        <span v-if="subscription.active && !subscription.cancelled && !subscription.cancel_at_period_end">Active</span>
                        ·
                        <a class="text-primary no-underline" :href="basePath+'/cashier-tool/user/'+resourceId">
                            Manage
                        </a>
                    </p>
                </div>
            </div>

            <div v-if="addon_subscriptions" class="overflow-hidden overflow-x-auto relative">
                <table cellpadding="0" cellspacing="0" data-testid="resource-table" class="table w-full">
                    <thead>
                    <tr>
                        <th class="text-left"><span class="cursor-pointer inline-flex items-center">Pricing Plan</span></th>
                        <th class="text-left"><span class="cursor-pointer inline-flex items-center">Price</span></th>
                        <th class="text-left"><span class="cursor-pointer inline-flex items-center">Quantity</span></th>
                    </tr>
                    </thead>
                    <tbody>
                        <tr v-for="addon in addon_subscriptions">
                            <td><strong>{{ addon.addon.name }} {{ addon.addonPlan.name }}</strong> &#8212; #{{ addon.id }}</td>
                            <td>{{addon.addonPlan.price}} / {{ addon.addonPlan.usageType == "licensed" ? addon.addonPlan.interval : addon.addonPlan.unit }}</td>
                            <td>
                                <span v-if="addon.addonPlan.usageType == 'metered'">
                                    {{ addon.current_usage }} {{ addon.addonPlan.unit }}
                                </span>
                                <span v-else>{{ addon.subscription.quantity }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </loading-view>


    </div>
</template>

<style>
    /* Scopes Styles */
</style>
