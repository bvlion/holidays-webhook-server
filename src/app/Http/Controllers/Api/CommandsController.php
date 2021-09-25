<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Models\Command;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Http\Requests\CommandRequest;

class CommandsController extends BaseApiController
{
  public function index(Request $request)
  {
    $user = $request->user();

    return Command::where(function($query) use($user) {
        $query->where('target_id', $user->id)
              ->where('target_type', 'user');
    })->orWhere(function($query) use($user) {
        $query->where('target_id', $user->groups_id)
              ->where('target_type', 'group');
    })->whereNull('deleted_at')->get();
  }

  public function store(CommandRequest $request)
  {
    $command = new Command();
    $command->target_type = $request->target_type;
    if ($request->target_type == 'user') {
      $command->target_id = $request->user()->id;
    } else {
      $command->target_id = $request->user()->groups_id;
    } 
    $command->target_name = $request->target_name;
    $command->url = $request->url;
    $command->method = $request->method;
    $command->body_type = $request->body_type;
    if ($request->has('headers_values')) {
      $command->headers = $request->headers_values;
    } else {
      $command->headers = '';
    }
    if ($request->has('parameters')) {
      $command->parameters = $request->parameters;
    } else {
      $command->parameters = '';
    }
    $command->save();
    return ['result', $command];
  }

  public function update(Request $request, int $id)
  {
    $command = Command::find($id);
    $this->checkExecutableUser(
      $command->target_type,
      $command->target_id,
      $request->user(),
      'update'
    );
    
    if ($request->has('target_type')) {
      $command->target_type = $request->target_type;
      if ($request->target_type == 'user') {
        $command->target_id = $request->user()->id;
      } elseif ($request->target_type == 'group') {
        $command->target_id = $request->user()->groups_id;
      } else {
        throw new HttpException(400, 'target_type must be user or group');
      } 
    }

    if ($request->has('target_name')) {
      $command->target_name = $request->target_name;
    }
    if ($request->has('url')) {
      $command->url = $request->url;
    }
    if ($request->has('method')) {
      $command->method = $request->method;
    }
    if ($request->has('body_type')) {
      $command->body_type = $request->body_type;
    }
    if ($request->has('headers_values')) {
      $command->headers = $request->headers_values;
    }
    if ($request->has('parameters')) {
      $command->parameters = $request->parameters;
    }
    $command->save();

    return ['result', $command];
  }

  public function destroy(Request $request, int $id)
  {
    $command = Command::find($id);
    $this->checkExecutableUser(
      $command->target_type,
      $command->target_id,
      $request->user(),
      'delete'
    );
    $command->deleted_at = date('Y-m-d H:i:s');
    return ['result', $command->save()];
  }
}
