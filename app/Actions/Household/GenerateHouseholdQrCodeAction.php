<?php

namespace App\Actions\Household;

use App\Models\Household;
use Illuminate\Http\Response;
use Lorisleiva\Actions\Concerns\AsAction;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class GenerateHouseholdQrCodeAction
{
    use AsAction;

    public function handle(Household $household): string
    {
        $deep_link = "flatshare://join?code={$household->join_code}";

        return QrCode::size(250)
            ->errorCorrection('H')
            ->generate($deep_link);
    }

    public function asController(Household $household): Response
    {
        try {
            $svg = $this->handle($household);

            return response($svg)->header('Content-Type', 'image/svg+xml');
        } catch (\Throwable $e) {
            report($e);

            return response(['message' => __('app.failed_action')], 500);
        }
    }
}
