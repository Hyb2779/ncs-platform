<tr class="border-b border-[#232B39]">
    <td class="px-3 py-2 font-numeric">{{ $fixture->starts_at->timezone(auth()->user()->timezone ?? 'UTC')->format('H:i') }}</td>
    <td class="px-3 py-2 font-numeric">{{ $fixture->bulletin_code }}</td>
    <td class="px-3 py-2"><a href="{{ route('site.sport.show', $fixture) }}">{{ $fixture->home->name }} - {{ $fixture->away->name }}</a></td>
    <td class="px-2 py-2"><div class="flex gap-1">@include('site.sport._odd', ['market' => '1X2', 'outcome' => 'home'])@include('site.sport._odd', ['market' => '1X2', 'outcome' => 'draw'])@include('site.sport._odd', ['market' => '1X2', 'outcome' => 'away'])</div></td>
    <td class="px-2 py-2"><div class="flex gap-1">@include('site.sport._odd', ['market' => 'OU25', 'outcome' => 'over'])@include('site.sport._odd', ['market' => 'OU25', 'outcome' => 'under'])</div></td>
    <td class="px-2 py-2"><div class="flex gap-1">@include('site.sport._odd', ['market' => 'BTTS', 'outcome' => 'yes'])@include('site.sport._odd', ['market' => 'BTTS', 'outcome' => 'no'])</div></td>
    <td class="px-3 py-2 text-end"><a href="{{ route('site.sport.show', $fixture) }}">{{ __('sport.other', ['count' => $fixture->odds->pluck('market_id')->unique()->count()]) }}</a></td>
</tr>
