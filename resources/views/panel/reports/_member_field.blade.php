@php
    $memberOptions = $members->map(fn ($member) => ['id' => $member->id, 'name' => $member->username])->values();
    $selectedMember = (string) request('member', '');
    $selectedName = $members->firstWhere('id', (int) $selectedMember)?->username ?? '';
@endphp
<label class="relative grid gap-1 text-sm" x-data="{ q: @js($selectedName), id: @js($selectedMember), open: false, members: @js($memberOptions) }">
    <span>{{ __('panel.member_movements_member') }}</span>
    <input type="hidden" name="member" :value="id">
    <input class="{{ $input }}" type="search" x-model="q" placeholder="{{ __('panel.member_movements_search') }}" autocomplete="off" @focus="open = true" @click="open = true" @input="if (q === '') id = ''">
    <div class="absolute start-0 top-full z-30 mt-1 max-h-60 w-full overflow-auto rounded-md border border-slate-300 bg-white shadow" x-show="open" x-cloak @click.outside="open = false">
        <button class="flex min-h-11 w-full items-center px-3 text-start text-sm hover:bg-slate-50" type="button" @click="id = ''; q = ''; open = false">{{ __('panel.member_movements_all') }}</button>
        <template x-for="m in members.filter(m => q.trim() === '' || m.name.toLowerCase().includes(q.trim().toLowerCase())).slice(0, 40)" :key="m.id">
            <button class="flex min-h-11 w-full items-center px-3 text-start text-sm hover:bg-slate-50" type="button" @click="id = String(m.id); q = m.name; open = false" x-text="m.name"></button>
        </template>
    </div>
</label>
