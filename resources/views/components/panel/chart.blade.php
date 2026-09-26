@props(['type' => 'line', 'labels' => [], 'datasets' => []])

<div {{ $attributes->class(['relative h-52 w-full md:h-72']) }}>
    <canvas data-chart="@json(['type' => $type, 'labels' => array_values($labels), 'datasets' => array_values($datasets)])"></canvas>
</div>
