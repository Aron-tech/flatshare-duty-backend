<?php

namespace App\Actions\Household;

use App\Models\Household;
use Illuminate\Http\Response;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Lorisleiva\Actions\Concerns\AsAction;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * The QR code contains the join code, so only the members of the household can get it.
 */
#[Authorize('view', 'household')]
class GenerateHouseholdQrCodeAction
{
    use AsAction;

    /**
     * @return string the QR code of the join deep link as SVG
     */
    public function handle(Household $household): string
    {
        return QrCode::size(250)
            ->errorCorrection('H')
            ->generate("flatshare://join?code={$household->join_code}");
    }

    public function asController(Household $household): Response
    {
        return response($this->handle($household), Response::HTTP_OK, ['Content-Type' => 'image/svg+xml']);
    }
}
