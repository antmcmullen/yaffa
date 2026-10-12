<template>
  <div class="card mb-3">
    <div class="card-header">
      <div class="card-title">
        {{ __('Current assets') }}
      </div>
    </div>
    <div class="card-body">
      <dl class="row mb-0">
        <dt class="col-8">{{ __('Owned quantity') }}</dt>
        <dd class="col-4">
          {{ formatQuantity(investment.current_quantity) }}
        </dd>
        <dt class="col-8">{{ __('Latest price') }}</dt>
        <dd
          class="col-4"
          :class="{ 'text-muted fst-italic': investment.latest_price == null }"
        >
          <template v-if="investment.latest_price == null">
            {{ __('Unknown (unpriced)') }}
          </template>
          <template v-else>
            {{
              toFormattedCurrency(
                investment.latest_price,
                locale,
                investment.currency,
                'detailed',
              )
            }}
          </template>
        </dd>
        <dt class="col-8">{{ __('Latest owned value') }}</dt>
        <dd
          class="col-4"
          :class="{ 'text-muted fst-italic': latestOwnedValue == null }"
        >
          <template v-if="latestOwnedValue == null">
            {{ __('Unknown (unpriced)') }}
          </template>
          <template v-else>
            {{
              toFormattedCurrency(latestOwnedValue, locale, investment.currency)
            }}
          </template>
        </dd>
      </dl>
    </div>
  </div>
</template>

<script>
  import {
    __,
    toFormattedCurrency,
    toFormattedQuantity,
  } from '@/shared/lib/i18n';
  import { getInvestmentValue } from '@/investments/lib/valuation';

  export default {
    name: 'CurrentAssetsCard',
    props: {
      investment: {
        type: Object,
        required: true,
      },
    },
    data() {
      return {
        locale: window.YAFFA.userSettings.locale,
      };
    },
    computed: {
      latestOwnedValue() {
        return getInvestmentValue(
          this.investment.current_quantity,
          this.investment.latest_price,
        );
      },
    },
    methods: {
      toFormattedCurrency,
      formatQuantity(value) {
        if (value === 0) {
          return '0';
        }
        return toFormattedQuantity(value, this.locale);
      },
      __,
    },
  };
</script>
