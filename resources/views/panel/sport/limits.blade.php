@extends('layouts.panel')

@section('heading', __('sport.panel.limits'))

@section('content')
    @php
        $separators = \App\Services\Sport\SportLimitFields::separators(app()->getLocale());
        $decimal = $separators['decimal'];
        $thousands = $separators['thousands'];
        $symbol = match ($currency->value) {
            'USD' => '$',
            'EUR' => '€',
            default => '₺',
        };
        $fields = \App\Services\Sport\SportLimitFields::class;
    @endphp
    <div
        x-data="sportLimitsPage(@js(__('sport.panel.change_count', ['count' => ':count'])))"
        @limit-dirty="recount()"
    >
        @if (auth()->user()->role->value === 'owner')
            <div class="mb-4 flex gap-2">
                @foreach (['TRY', 'USD', 'EUR'] as $code)
                    <a class="inline-flex h-11 items-center rounded-lg border px-3 text-sm {{ $currency->value === $code ? 'bg-[#161A22] text-white' : '' }}" href="{{ route('panel.sport.limits', ['currency' => $code]) }}">{{ $code }}</a>
                @endforeach
            </div>
        @endif
        <form id="sport-limits-form" class="pb-20 md:pb-24" method="POST" action="{{ route('panel.sport.limits.update') }}" @submit="allowLeave()">
            @csrf
            @method('PUT')
            <input type="hidden" name="currency" value="{{ $currency->value }}">
            <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
                @foreach ($groups as $group => $groupFields)
                    <x-panel.card :title="__('sport.panel.limit_groups.'.$group)" accordion>
                            @foreach ($groupFields as $field)
                                @if ($field === 'cash_out_enabled')
                                    @php
                                        $cap = $ceiling->get($field);
                                        $locked = $cap !== true;
                                        $savedOn = (bool) ($limit->exists ? $limit->cash_out_enabled : false);
                                        $on = ! $locked && (bool) old('cash_out_enabled', $savedOn);
                                        $error = $errors->first('cash_out_enabled');
                                    @endphp
                                    <x-panel.form-row
                                        data-limit-field="cash_out_enabled"
                                        x-data="cashOutRow()"
                                        data-on="{{ $on ? '1' : '0' }}"
                                        data-original="{{ $savedOn ? '1' : '0' }}"
                                        data-locked="{{ $locked ? '1' : '0' }}"
                                        x-bind:data-limit-dirty="dirty ? '1' : '0'"
                                        x-bind:class="dirty ? 'border-s-[var(--accent)]' : 'border-s-transparent'"
                                        :label="__('sport.panel.limit_fields.cash_out_enabled')"
                                        :error="$error"
                                        :hint="$locked ? __('sport.panel.cash_out_closed') : null"
                                    >
                                        <button
                                            class="relative inline-flex h-11 w-14 shrink-0 items-center rounded-full disabled:opacity-50"
                                            type="button"
                                            role="switch"
                                            x-bind:aria-checked="on ? 'true' : 'false'"
                                            x-bind:class="on ? 'bg-[var(--accent)]' : 'bg-slate-300'"
                                            x-bind:disabled="locked"
                                            @disabled($locked)
                                            @click="toggle()"
                                        >
                                            <span class="absolute top-2.5 h-6 w-6 rounded-full bg-white" x-bind:class="on ? 'end-1' : 'start-1'"></span>
                                        </button>
                                        <button class="inline-flex h-11 w-11 shrink-0 items-center justify-center text-base text-slate-500" type="button" x-show="dirty" x-cloak @click="revert()" aria-label="{{ __('sport.panel.revert_field') }}">↺</button>
                                        <input type="hidden" name="cash_out_enabled" value="1" x-bind:disabled="locked || !on" @disabled($locked || ! $on)>
                                    </x-panel.form-row>
                                    @continue
                                @endif
                                @php
                                    $floor = $fields::isFloor($field);
                                    $kind = $fields::kind($field);
                                    $cap = $ceiling->get($field);
                                    $canOpen = $cap === null;
                                    $savedRaw = $fields::canonical($field, $limit->exists ? $limit->{$field} : $cap);
                                    $savedOpen = $canOpen && ($limit->exists ? $limit->{$field} === null : true);
                                    $open = $canOpen && (string) old('unlimited.'.$field, $savedOpen ? '1' : '') === '1';
                                    $raw = $fields::canonical($field, old($field, $savedRaw === '' ? null : $savedRaw));
                                    $hintValue = $cap === null ? '' : $fields::formatHint($field, $fields::canonical($field, $cap), $decimal, $thousands, $symbol);
                                    $hint = $cap === null
                                        ? __($floor ? 'sport.panel.floor_unlimited' : 'sport.panel.ceiling_unlimited')
                                        : __($floor ? 'sport.panel.floor' : 'sport.panel.ceiling', ['value' => $hintValue]);
                                    $error = $errors->first($field);
                                    $suffix = $fields::suffix($field, $symbol);
                                    $shown = $open ? '' : $fields::formatInput($field, $raw, $decimal, $thousands);
                                @endphp
                                <x-panel.form-row
                                    data-limit-field="{{ $field }}"
                                    x-data="limitRow()"
                                    data-raw="{{ $raw }}"
                                    data-original="{{ $savedRaw }}"
                                    data-open="{{ $open ? '1' : '0' }}"
                                    data-original-open="{{ $savedOpen ? '1' : '0' }}"
                                    data-kind="{{ $kind }}"
                                    data-decimal="{{ $decimal }}"
                                    data-thousands="{{ $thousands }}"
                                    x-bind:data-limit-dirty="dirty ? '1' : '0'"
                                    x-bind:class="dirty ? 'border-s-[var(--accent)]' : 'border-s-transparent'"
                                    :label="__('sport.panel.limit_fields.'.$field)"
                                    :error="$error ?: null"
                                    :hint="$error ? null : $hint"
                                >
                                    <div class="relative w-[120px] shrink-0 lg:w-[180px]">
                                        <input
                                            class="h-11 w-full rounded-md border pe-8 ps-2 text-end font-numeric text-sm disabled:bg-slate-50 {{ $error ? 'border-red-600' : 'border-[#E3E6EB]' }}"
                                            type="text"
                                            inputmode="decimal"
                                            autocomplete="off"
                                            x-model="display"
                                            x-bind:disabled="open"
                                            @if ($canOpen) placeholder="{{ __('sport.panel.unlimited') }}" @endif
                                            @input="onInput()"
                                            @blur="commit()"
                                            value="{{ $shown }}"
                                        >
                                        @if ($suffix !== '')
                                            <span class="pointer-events-none absolute inset-y-0 end-2 flex items-center text-xs text-slate-400">{{ $suffix }}</span>
                                        @endif
                                    </div>
                                    @if ($canOpen)
                                        <button
                                            class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md border text-lg"
                                            type="button"
                                            x-bind:class="open ? 'border-[var(--accent)] bg-[var(--accent)] text-[#1A1305]' : 'border-[#E3E6EB] text-slate-500'"
                                            x-bind:aria-pressed="open ? 'true' : 'false'"
                                            @click="toggle()"
                                            aria-label="{{ __('sport.panel.unlimited') }}"
                                        >∞</button>
                                    @endif
                                    <button class="inline-flex h-11 w-11 shrink-0 items-center justify-center text-base text-slate-500" type="button" x-show="dirty" x-cloak @click="revert()" aria-label="{{ __('sport.panel.revert_field') }}">↺</button>
                                    <input type="hidden" name="{{ $field }}" value="{{ $open ? '' : $raw }}" x-bind:value="open ? '' : raw">
                                    @if ($canOpen)
                                        <input type="hidden" name="unlimited[{{ $field }}]" value="1" x-bind:disabled="!open" @disabled(! $open)>
                                    @endif
                                </x-panel.form-row>
                            @endforeach
                    </x-panel.card>
                @endforeach
            </div>
        </form>
        <form id="sport-limits-restore" method="POST" action="{{ route('panel.sport.limits.restore') }}" @submit="allowLeave()">
            @csrf
            <input type="hidden" name="currency" value="{{ $currency->value }}">
        </form>
        <x-panel.sticky-actions>
            <p class="min-w-0 flex-1 text-sm text-slate-600" x-text="text()">{{ __('sport.panel.change_count', ['count' => 0]) }}</p>
            <button class="inline-flex h-11 shrink-0 items-center rounded-lg border px-3 text-sm" type="submit" form="sport-limits-restore">{{ __('sport.panel.restore') }}</button>
            <button class="inline-flex h-11 shrink-0 items-center rounded-lg bg-[var(--accent)] px-4 text-sm font-semibold text-[#1A1305] disabled:opacity-40" type="submit" form="sport-limits-form" x-bind:disabled="changes === 0" disabled>{{ __('sport.panel.save') }}</button>
        </x-panel.sticky-actions>
    </div>
    <style>
        details.limit-group > summary .limit-chevron::before { content: '+'; }
        details[open].limit-group > summary .limit-chevron::before { content: '−'; }
    </style>
    <script>
        function syncLimitGroups(initial) {
            var desktop = window.matchMedia('(min-width: 1024px)').matches;
            document.querySelectorAll('details.limit-group').forEach(function (group, index) {
                if (desktop) {
                    group.open = true;
                } else if (initial) {
                    group.open = index === 0;
                }
            });
        }
        syncLimitGroups(true);
        window.matchMedia('(min-width: 1024px)').addEventListener('change', function (event) {
            if (event.matches) {
                syncLimitGroups(false);
            }
        });
        function limitGroupNumber(raw, scale, decimal, thousands) {
            if (raw === '') {
                return '';
            }
            var negative = raw.charAt(0) === '-';
            var digits = negative ? raw.slice(1) : raw;
            var whole = digits.split('.')[0] || '0';
            var fraction = '';
            if (scale > 0) {
                fraction = ((digits.split('.')[1] || '') + '00').slice(0, scale);
            }
            whole = whole.replace(/\B(?=(\d{3})+(?!\d))/g, thousands);
            return (negative ? '-' : '') + whole + (scale > 0 ? decimal + fraction : '');
        }

        window.limitRow = function () {
            return {
                raw: '',
                original: '',
                open: false,
                originalOpen: false,
                kind: 'int',
                decimal: '.',
                thousands: ',',
                display: '',
                dirty: false,
                init: function () {
                    var el = this.$el;
                    this.raw = el.dataset.raw || '';
                    this.original = el.dataset.original || '';
                    this.open = el.dataset.open === '1';
                    this.originalOpen = el.dataset.originalOpen === '1';
                    this.kind = el.dataset.kind;
                    this.decimal = el.dataset.decimal || '.';
                    this.thousands = el.dataset.thousands || ',';
                    this.display = this.open ? '' : this.format(this.raw);
                    this.sync();
                },
                format: function (raw) {
                    if (this.kind === 'odds') {
                        return limitGroupNumber(raw, 2, '.', ',');
                    }
                    if (this.kind === 'money') {
                        return limitGroupNumber(raw, 2, this.decimal, this.thousands);
                    }
                    return limitGroupNumber(raw, 0, this.decimal, this.thousands);
                },
                parse: function (text) {
                    var cleaned = String(text || '');
                    if (this.kind === 'odds') {
                        cleaned = cleaned.replace(/,/g, '').replace(/[^\d.]/g, '');
                    } else if (this.kind === 'int' || this.kind === 'minute') {
                        cleaned = cleaned.replace(/[^\d]/g, '');
                        return cleaned === '' ? '' : String(parseInt(cleaned, 10));
                    } else {
                        cleaned = cleaned.split(this.thousands).join('').replace(this.decimal, '.').replace(/[^\d.]/g, '');
                    }
                    if (cleaned === '' || cleaned === '.') {
                        return '';
                    }
                    var parts = cleaned.split('.');
                    return parts[0] + '.' + ((parts[1] || '') + '00').slice(0, 2);
                },
                onInput: function () {
                    if (this.open) {
                        return;
                    }
                    this.raw = this.parse(this.display);
                    this.sync();
                },
                commit: function () {
                    if (this.open) {
                        this.display = '';
                        return;
                    }
                    this.raw = this.parse(this.display);
                    this.display = this.format(this.raw);
                    this.sync();
                },
                toggle: function () {
                    this.open = !this.open;
                    if (this.open) {
                        this.display = '';
                    } else {
                        if (this.raw === '') {
                            this.raw = this.original;
                        }
                        this.display = this.format(this.raw);
                    }
                    this.sync();
                },
                revert: function () {
                    this.open = this.originalOpen;
                    this.raw = this.original;
                    this.display = this.open ? '' : this.format(this.raw);
                    this.sync();
                },
                sync: function () {
                    this.dirty = this.open !== this.originalOpen || (!this.open && this.raw !== this.original);
                    this.$dispatch('limit-dirty');
                },
            };
        };

        window.cashOutRow = function () {
            return {
                on: false,
                original: false,
                locked: false,
                dirty: false,
                init: function () {
                    var el = this.$el;
                    this.on = el.dataset.on === '1';
                    this.original = el.dataset.original === '1';
                    this.locked = el.dataset.locked === '1';
                    this.sync();
                },
                toggle: function () {
                    if (this.locked) {
                        return;
                    }
                    this.on = !this.on;
                    this.sync();
                },
                revert: function () {
                    this.on = this.original;
                    this.sync();
                },
                sync: function () {
                    this.dirty = this.on !== this.original;
                    this.$dispatch('limit-dirty');
                },
            };
        };

        window.sportLimitsPage = function (pattern) {
            return {
                changes: 0,
                bypass: false,
                pattern: pattern,
                init: function () {
                    var page = this;
                    this.$nextTick(function () {
                        page.recount();
                    });
                    window.addEventListener('beforeunload', function (event) {
                        if (page.bypass || page.changes === 0) {
                            return;
                        }
                        event.preventDefault();
                        event.returnValue = '';
                    });
                },
                recount: function () {
                    var page = this;
                    this.$nextTick(function () {
                        page.changes = page.$root.querySelectorAll('[data-limit-dirty="1"]').length;
                    });
                },
                text: function () {
                    return String(this.pattern).replace(':count', String(this.changes));
                },
                allowLeave: function () {
                    this.bypass = true;
                },
            };
        };
    </script>
@endsection
