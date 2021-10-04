<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use \File;

class RedocController extends Controller
{
  public function index()
  {
    return File::get(base_path() . '/redoc/index.html');
  }

  public function yaml()
  {
    return File::get(base_path() . '/redoc/openapi.yml');
  }
}