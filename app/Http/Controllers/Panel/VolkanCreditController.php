<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Stats\VolkanCredit;
use App\Support\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Volkan'ın net kredi üretimi ve dağıtımı. Yalnızca kök owner. */
class VolkanCreditController extends Controller
{
    public function index(Request $request, VolkanCredit $credit): View
    {
        abort_unless($request->user()?->isRootOwner() === true, 403);

        $zone = $request->user()->timezone ?: 'Europe/Istanbul';
        [$period, $from, $to] = ReportPeriod::resolve($request, $zone, 'this_week');

        $subject = $credit->subject();

        return view('panel.volkan_credit.index', [
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $subject === null ? [] : $credit->rows($subject, $from, $to),
        ]);
    }
}
