<p class="mb-2 text-sm text-[#9AA4B5]">{{ __('site.categories') }}</p>
<a class="block py-2" href="{{ request()->url() }}">{{ __('site.all') }}</a>
<a class="block py-2" href="?list=favorites">{{ __('site.favorites') }}</a>
<a class="block py-2" href="?list=recent">{{ __('site.recent') }}</a>
<p class="mb-2 mt-4 text-sm text-[#9AA4B5]">{{ __('site.providers') }}</p>
@foreach ($providers as $provider)
    <a class="block py-2" href="?provider={{ $provider->id }}">{{ $provider->name }}</a>
@endforeach
