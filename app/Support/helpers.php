<?php

use App\Support\Brand;

function brand(): Brand
{
    return app(Brand::class);
}
