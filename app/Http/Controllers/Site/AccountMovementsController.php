<?php

namespace App\Http\Controllers\Site;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\AccountStatement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AccountMovementsController extends Controller
{
    public function __construct(private readonly AccountStatement $statement) {}

    public function index(Request $request): View|JsonResponse|Response
    {
        $user = $request->user();
        abort_unless($user !== null && $user->role === UserRole::Uye, 404);

        $filters = $this->statement->filters($request, $user);
        $payload = $filters['tab'] === 'games'
            ? $this->statement->games($user, $filters)
            : $this->statement->balance($user, $filters);

        $viewData = [
            'tab' => $filters['tab'],
            'period' => $filters['period'],
            'direction' => $filters['direction'],
            'from' => $filters['from'],
            'to' => $filters['to'],
            'rows' => $payload['rows'],
            'next' => $payload['next'],
            'summary' => $payload['summary'],
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'cards' => view('site.account._cards', $viewData)->render(),
                'rows' => view('site.account._rows', $viewData)->render(),
                'next' => $payload['next'],
            ])->header('Cache-Control', 'private, no-store');
        }

        return response()
            ->view('site.account.movements', $viewData)
            ->header('Cache-Control', 'private, no-store');
    }
}
