<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Stats\VolkanCredit;
use App\Support\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Alt owner'ların dağıttığı kredi. Yalnızca kök owner. */
class VolkanCreditController extends Controller
{
    public function index(Request $request, VolkanCredit $credit): View
    {
        abort_unless($request->user()?->isRootOwner() === true, 403);

        $zone = $request->user()->timezone ?: 'Europe/Istanbul';
        [$period, $from, $to] = ReportPeriod::resolve($request, $zone, 'this_week');

        $groups = [];
        foreach ($credit->subjects() as $sub) {
            foreach ($credit->tables($sub, $from, $to, $zone) as $table) {
                $code = $table['currency']->value;
                $groups[$code] ??= ['currency' => $table['currency'], 'rows' => [], 'total' => '0.00'];
                $groups[$code]['rows'][] = [
                    'username' => $sub->username,
                    'total' => $table['distributed'],
                    'days' => $table['days'],
                ];
                $groups[$code]['total'] = bcadd($groups[$code]['total'], $table['distributed'], 2);
            }
        }

        return view('panel.volkan_credit.index', [
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'groups' => array_values($groups),
        ]);
    }
}
