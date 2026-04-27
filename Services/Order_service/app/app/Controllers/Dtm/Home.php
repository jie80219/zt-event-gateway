<?php

namespace App\Controllers\v1;

class Home extends BaseController
{
    public function index()
    {
        return view('welcome_message');
    }
}
