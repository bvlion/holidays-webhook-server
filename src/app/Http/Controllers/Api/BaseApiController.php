<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BaseApiController extends Controller
{
  protected function checkExecutableUser(
    string $target_type,
    int $target_id,
    User $user,
    string $target_permission
    )
  {
    if (
      ($target_type == 'user' && $target_id == $user->id) ||
      ($target_type == 'group' && $target_id == $user->groups_id)
    ) {
      return;
    }
    throw new HttpException(403, "Haven't $target_permission permission");
  }
}