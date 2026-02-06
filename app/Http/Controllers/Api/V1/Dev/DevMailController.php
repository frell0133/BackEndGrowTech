<?php

namespace App\Http\Controllers\Api\V1\Dev;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class DevMailController extends Controller
{
    use ApiResponse;

    public function test(Request $request)
    {
        $data = $request->validate([
            'to' => ['required','email'],
        ]);

        Mail::raw('Test email Brevo dari GrowTech Central (Laravel).', function ($m) use ($data) {
            $m->to($data['to'])->subject('Brevo SMTP Test');
        });

        return $this->ok(['sent' => true, 'to' => $data['to']]);
    }
}
