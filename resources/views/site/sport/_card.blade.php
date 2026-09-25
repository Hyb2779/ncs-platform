<article class="rounded-lg bg-[#151A23] p-3">
    <p class="font-numeric text-sm text-[#9AA4B5]">{{ $fixture->starts_at->timezone(auth()->user()->timezone ?? 'UTC')->format('H:i') }} · {{ $fixture->bulletin_code }}</p>
    <a class="mt-1 block" href="{{ route('site.sport.show', $fixture) }}">{{ $fixture->home->name }} - {{ $fixture->away->name }}</a>
    <div class="mt-3 flex gap-2">
        @include('site.sport._odd', ['market' => '1X2', 'outcome' => 'home'])
        @include('site.sport._odd', ['market' => '1X2', 'outcome' => 'draw'])
        @include('site.sport._odd', ['market' => '1X2', 'outcome' => 'away'])
    </div>
</article>
