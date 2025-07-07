<?php

namespace App\Nscreative\Src\Classes;

use Illuminate\Support\Facades\Http;

class Nscreative
{
    /* SMS */
    public function sendSms($to, $content)
    {
        $data = array(
            'client' => config('nscreative.notifications.wirepick.client'),
            'password' => config('nscreative.notifications.wirepick.password'),
            'phone' => $to,
            'from' => config('nscreative.notifications.wirepick.from'),
            'text' => $content
        );

        $req = Http::asForm()->get('https://api.wirepick.com/httpsms/send', $data);

        return simplexml_load_string($req);
    }
}
