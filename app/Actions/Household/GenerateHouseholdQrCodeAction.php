<?php

namespace App\Actions\Household;

use App\Models\Household;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Lorisleiva\Actions\Concerns\AsAction;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class GenerateHouseholdQrCodeAction
{
    use AsAction;

    /**
     * The QR code contains the join code, so only the members of the household can get it.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household): string
    {
        if (! $user->households()->whereKey($household->id)->exists()) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        $deep_link = "flatshare://join?code={$household->join_code}";

        return QrCode::size(250)
            ->errorCorrection('H')
            ->generate($deep_link);
    }

    public function asController(Request $request, Household $household): Response
    {
        try {
            $svg = $this->handle($request->user(), $household);

            return response($svg)->header('Content-Type', 'image/svg+xml');
        } catch (AuthorizationException $e) {
            return response(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response(['message' => __('app.failed_action')], 500);
        }
    }
}
